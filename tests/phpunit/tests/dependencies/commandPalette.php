<?php
/**
 * Tests for the conditional loading of the Command Palette assets.
 *
 * The Command Palette is delivered by the `wp-commands` and `wp-core-commands` bundles,
 * which pull in the `wp-components` dependency chain. `wp_should_load_command_palette_assets()`
 * decides whether those bundles are enqueued on the current screen, and
 * `wp_enqueue_command_palette_assets()` consults it before doing any work.
 *
 * These tests lock in the delivery-only contract: the gate changes what is *enqueued*,
 * never what is *registered*, and it never touches the dependency system itself.
 *
 * @group dependencies
 * @group scripts
 *
 * @covers ::wp_should_load_command_palette_assets
 * @covers ::wp_enqueue_command_palette_assets
 */
class Tests_Dependencies_CommandPalette extends WP_UnitTestCase {

	/**
	 * The script handles the Command Palette is delivered by.
	 *
	 * @var string[]
	 */
	const SCRIPT_HANDLES = array( 'wp-commands', 'wp-core-commands' );

	/**
	 * The style handle the Command Palette is delivered by.
	 *
	 * @var string
	 */
	const STYLE_HANDLE = 'wp-commands';

	/**
	 * The handle the inline initializer is attached to.
	 *
	 * @var string
	 */
	const INITIALIZER_HANDLE = 'wp-core-commands';

	/**
	 * Backup of the `wp_scripts` global.
	 *
	 * @var WP_Scripts|null
	 */
	protected $old_wp_scripts;

	/**
	 * Backup of the `wp_styles` global.
	 *
	 * @var WP_Styles|null
	 */
	protected $old_wp_styles;

	public function set_up() {
		parent::set_up();

		/*
		 * Reset the dependency globals so that every test starts from the real default
		 * registrations, without inheriting anything enqueued by a previous test.
		 */
		$this->old_wp_scripts  = $GLOBALS['wp_scripts'] ?? null;
		$this->old_wp_styles   = $GLOBALS['wp_styles'] ?? null;
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
	}

	public function tear_down() {
		$GLOBALS['wp_scripts'] = $this->old_wp_scripts;
		$GLOBALS['wp_styles']  = $this->old_wp_styles;

		parent::tear_down();
	}

	/**
	 * The Command Palette does not exist outside the admin, so the gate is always closed there.
	 */
	public function test_should_not_load_outside_the_admin() {
		$this->assertFalse( is_admin(), 'The test should start on a front end request.' );
		$this->assertFalse( wp_should_load_command_palette_assets() );
	}

	/**
	 * The `! is_admin()` guard is deliberately not filterable, so the filter must not run there.
	 */
	public function test_filter_is_not_applied_outside_the_admin() {
		$filter_calls = 0;

		add_filter(
			'should_load_command_palette_assets',
			static function () use ( &$filter_calls ) {
				++$filter_calls;
				return true;
			}
		);

		$this->assertFalse( wp_should_load_command_palette_assets(), 'The front end guard should not be filterable.' );
		$this->assertSame( 0, $filter_calls, 'The filter should not be applied outside the admin.' );
	}

	/**
	 * Mimics `admin-ajax.php`, where the request is an admin request but no screen is set up.
	 *
	 * The `instanceof WP_Screen` check is the only thing preventing a fatal error there.
	 */
	public function test_should_not_load_when_current_screen_is_not_a_screen_object() {
		$GLOBALS['current_screen'] = new class() {
			/**
			 * Reports an admin request, the way WP_Screen::in_admin() does.
			 *
			 * @return bool
			 */
			public function in_admin() {
				return true;
			}
		};

		$this->assertTrue( is_admin(), 'The stubbed screen should report an admin request.' );
		$this->assertFalse( wp_should_load_command_palette_assets(), 'A non WP_Screen global must not open the gate.' );
	}

	/**
	 * The gate is closed on every admin screen that is not a block editor screen.
	 *
	 * @dataProvider data_non_block_editor_screens
	 *
	 * @param string $hook_name Admin screen hook name.
	 */
	public function test_should_not_load_on_non_block_editor_screens( $hook_name ) {
		set_current_screen( $hook_name );

		$this->assertTrue( is_admin(), "Screen `{$hook_name}` should report an admin request." );
		$this->assertFalse( get_current_screen()->is_block_editor(), "Screen `{$hook_name}` should not be a block editor screen." );
		$this->assertFalse( wp_should_load_command_palette_assets(), "The gate should be closed on `{$hook_name}`." );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_non_block_editor_screens() {
		return array(
			'dashboard'        => array( 'index.php' ),
			'posts list'       => array( 'edit.php' ),
			'media library'    => array( 'upload.php' ),
			'comments'         => array( 'edit-comments.php' ),
			'themes'           => array( 'themes.php' ),
			'plugins'          => array( 'plugins.php' ),
			'users'            => array( 'users.php' ),
			'tools'            => array( 'tools.php' ),
			'general settings' => array( 'options-general.php' ),
		);
	}

	/**
	 * The gate is open on a block editor screen, detected the same way as
	 * wp_should_load_block_editor_scripts_and_styles().
	 */
	public function test_should_load_on_a_block_editor_screen() {
		set_current_screen( 'post-new.php' );

		$this->assertTrue( get_current_screen()->is_block_editor(), 'The post editor should be a block editor screen.' );
		$this->assertTrue( wp_should_load_command_palette_assets() );
	}

	/**
	 * Screens such as the site editor and the block based widgets screen flag themselves as
	 * block editor screens, which is enough to open the gate.
	 */
	public function test_should_load_when_the_screen_flags_itself_as_a_block_editor() {
		set_current_screen( 'widgets.php' );
		get_current_screen()->is_block_editor( true );

		$this->assertTrue( wp_should_load_command_palette_assets() );
	}

	/**
	 * The filter can opt an otherwise unsupported screen back in.
	 */
	public function test_filter_can_open_the_gate_on_an_unsupported_screen() {
		set_current_screen( 'edit.php' );

		$this->assertFalse( wp_should_load_command_palette_assets(), 'The gate should start closed.' );

		add_filter( 'should_load_command_palette_assets', '__return_true' );

		$this->assertTrue( wp_should_load_command_palette_assets(), 'The filter should be able to open the gate.' );
	}

	/**
	 * The filter can opt a supported screen out.
	 */
	public function test_filter_can_close_the_gate_on_a_supported_screen() {
		set_current_screen( 'post-new.php' );

		$this->assertTrue( wp_should_load_command_palette_assets(), 'The gate should start open.' );

		add_filter( 'should_load_command_palette_assets', '__return_false' );

		$this->assertFalse( wp_should_load_command_palette_assets(), 'The filter should be able to close the gate.' );
	}

	/**
	 * The filter receives the screen based default as its first argument.
	 *
	 * @dataProvider data_filter_default_value
	 *
	 * @param string $hook_name Admin screen hook name.
	 * @param bool   $expected  Default value the filter is expected to receive.
	 */
	public function test_filter_receives_the_screen_based_default( $hook_name, $expected ) {
		set_current_screen( $hook_name );

		$received = null;

		add_filter(
			'should_load_command_palette_assets',
			static function ( $should_load ) use ( &$received ) {
				$received = $should_load;
				return $should_load;
			}
		);

		wp_should_load_command_palette_assets();

		$this->assertSame( $expected, $received );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_filter_default_value() {
		return array(
			'block editor screen'     => array( 'post-new.php', true ),
			'non block editor screen' => array( 'edit.php', false ),
		);
	}

	/**
	 * Nothing is enqueued on a front end request.
	 */
	public function test_enqueue_does_nothing_outside_the_admin() {
		$this->assertFalse( is_admin(), 'The test should start on a front end request.' );

		wp_enqueue_command_palette_assets();

		$this->assert_palette_assets_are_not_enqueued();
	}

	/**
	 * The gate changes delivery only: on an unsupported screen the handles stay registered,
	 * they are simply never enqueued, and no inline initializer is added.
	 */
	public function test_handles_stay_registered_but_are_not_enqueued_on_an_unsupported_screen() {
		set_current_screen( 'edit.php' );

		do_action( 'admin_enqueue_scripts', 'edit.php' );

		$this->assert_palette_assets_are_registered();
		$this->assert_palette_assets_are_not_enqueued();

		$this->assertFalse(
			wp_scripts()->get_data( self::INITIALIZER_HANDLE, 'after' ),
			'No inline initializer should be added on an unsupported screen.'
		);
	}

	/**
	 * Closing the gate with the filter on a supported screen must not deregister anything either.
	 */
	public function test_handles_stay_registered_when_the_filter_closes_the_gate() {
		set_current_screen( 'post-new.php' );
		add_filter( 'should_load_command_palette_assets', '__return_false' );

		do_action( 'admin_enqueue_scripts', 'post-new.php' );

		$this->assert_palette_assets_are_registered();
		$this->assert_palette_assets_are_not_enqueued();
	}

	/**
	 * On a supported screen all three handles are enqueued and the initializer is printed.
	 */
	public function test_assets_are_enqueued_on_a_supported_screen() {
		set_current_screen( 'post-new.php' );

		wp_enqueue_command_palette_assets();

		$this->assert_palette_assets_are_registered();

		foreach ( self::SCRIPT_HANDLES as $handle ) {
			$this->assertTrue( wp_script_is( $handle, 'enqueued' ), "Script `{$handle}` should be enqueued." );
		}
		$this->assertTrue( wp_style_is( self::STYLE_HANDLE, 'enqueued' ), 'Style `' . self::STYLE_HANDLE . '` should be enqueued.' );

		$after = wp_scripts()->get_data( self::INITIALIZER_HANDLE, 'after' );

		$this->assertIsArray( $after, 'The inline initializer should be attached to `' . self::INITIALIZER_HANDLE . '`.' );
		$this->assertStringContainsString(
			'wp.coreCommands.initializeCommandPalette(',
			implode( "\n", $after ),
			'The inline initializer should call initializeCommandPalette().'
		);
	}

	/**
	 * The initializer carries the settings payload the Command Palette is booted with.
	 */
	public function test_initializer_payload_is_valid_json() {
		set_current_screen( 'post-new.php' );

		wp_enqueue_command_palette_assets();

		$inline = implode( "\n", (array) wp_scripts()->get_data( self::INITIALIZER_HANDLE, 'after' ) );

		$this->assertSame(
			1,
			preg_match( '/wp\.coreCommands\.initializeCommandPalette\( (.*) \);/', $inline, $matches ),
			'The inline initializer should be printed exactly once.'
		);

		$settings = json_decode( $matches[1], true );

		$this->assertIsArray( $settings, 'The settings payload should be valid JSON.' );
		$this->assertArrayHasKey( 'is_network_admin', $settings, 'The settings payload should report the network admin context.' );
	}

	/**
	 * Delivery follows PHP truthiness of the filtered value, in both directions.
	 *
	 * @dataProvider data_filter_truthiness
	 *
	 * @param mixed $filtered_value Value returned by the filter.
	 * @param bool  $expected       Whether the assets are expected to be enqueued.
	 */
	public function test_delivery_follows_the_filtered_value( $filtered_value, $expected ) {
		set_current_screen( 'edit.php' );

		add_filter(
			'should_load_command_palette_assets',
			static function () use ( $filtered_value ) {
				return $filtered_value;
			}
		);

		do_action( 'admin_enqueue_scripts', 'edit.php' );

		if ( $expected ) {
			$this->assertTrue( wp_script_is( 'wp-commands', 'enqueued' ) );
			$this->assertTrue( wp_script_is( 'wp-core-commands', 'enqueued' ) );
			$this->assertTrue( wp_style_is( self::STYLE_HANDLE, 'enqueued' ) );
		} else {
			$this->assert_palette_assets_are_not_enqueued();
		}
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_filter_truthiness() {
		return array(
			'true'             => array( true, true ),
			'non empty string' => array( '1', true ),
			'non empty array'  => array( array( 'x' ), true ),
			'false'            => array( false, false ),
			'zero'             => array( 0, false ),
			'null'             => array( null, false ),
			'empty array'      => array( array(), false ),
		);
	}

	/**
	 * The assets are still delivered by the documented `admin_enqueue_scripts` registration,
	 * which is what keeps `remove_action()` working for existing sites.
	 */
	public function test_registration_on_admin_enqueue_scripts_is_intact() {
		$this->assertSame(
			10,
			has_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' ),
			'The Command Palette assets should still be enqueued from admin_enqueue_scripts at the default priority.'
		);
	}

	/**
	 * Removing the registered callback still removes the assets entirely.
	 */
	public function test_remove_action_prevents_the_assets_from_being_enqueued() {
		set_current_screen( 'post-new.php' );

		remove_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' );

		$this->assertFalse(
			has_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' ),
			'The callback should be removable.'
		);

		do_action( 'admin_enqueue_scripts', 'post-new.php' );

		$this->assert_palette_assets_are_registered();
		$this->assert_palette_assets_are_not_enqueued();
	}

	/**
	 * The positive control for the previous test: with the registration in place, firing the
	 * action on a supported screen delivers the assets.
	 */
	public function test_action_delivers_the_assets_on_a_supported_screen() {
		set_current_screen( 'post-new.php' );

		do_action( 'admin_enqueue_scripts', 'post-new.php' );

		foreach ( self::SCRIPT_HANDLES as $handle ) {
			$this->assertTrue( wp_script_is( $handle, 'enqueued' ), "Script `{$handle}` should be enqueued." );
		}
		$this->assertTrue( wp_style_is( self::STYLE_HANDLE, 'enqueued' ), 'Style `' . self::STYLE_HANDLE . '` should be enqueued.' );
	}

	/**
	 * Admin pages that render their own document never fire `admin_enqueue_scripts`, so they
	 * call `wp_enqueue_command_palette_assets()` directly. The screens they run on are not
	 * block editor screens, so screening a direct call would take the Command Palette away
	 * from them entirely.
	 */
	public function test_a_direct_call_is_not_screened_by_the_current_screen() {
		set_current_screen( 'index.php' );

		$this->assertFalse(
			wp_should_load_command_palette_assets(),
			'This test is only meaningful on a screen the gate would close.'
		);

		wp_enqueue_command_palette_assets();

		foreach ( self::SCRIPT_HANDLES as $handle ) {
			$this->assertTrue( wp_script_is( $handle, 'enqueued' ), "Script `{$handle}` should be enqueued by a direct call." );
		}
		$this->assertTrue(
			wp_style_is( self::STYLE_HANDLE, 'enqueued' ),
			'Style `' . self::STYLE_HANDLE . '` should be enqueued by a direct call.'
		);
	}

	/**
	 * The filter screens the hooked delivery. A page that asks for the Command Palette by
	 * name still receives it.
	 */
	public function test_a_direct_call_is_not_screened_by_the_filter() {
		set_current_screen( 'index.php' );
		add_filter( 'should_load_command_palette_assets', '__return_false' );

		wp_enqueue_command_palette_assets();

		foreach ( self::SCRIPT_HANDLES as $handle ) {
			$this->assertTrue( wp_script_is( $handle, 'enqueued' ), "Script `{$handle}` should be enqueued by a direct call." );
		}
	}

	/**
	 * The contrast that defines the split, on one and the same screen: the hooked delivery is
	 * screened, and a direct call on that very screen is honored.
	 */
	public function test_the_hooked_delivery_is_screened_where_a_direct_call_is_honored() {
		set_current_screen( 'index.php' );

		do_action( 'admin_enqueue_scripts', 'index.php' );

		$this->assert_palette_assets_are_not_enqueued();

		wp_enqueue_command_palette_assets();

		foreach ( self::SCRIPT_HANDLES as $handle ) {
			$this->assertTrue( wp_script_is( $handle, 'enqueued' ), "Script `{$handle}` should be enqueued by a direct call." );
		}
	}

	/**
	 * A call made from another `admin_enqueue_scripts` callback is part of the hooked delivery
	 * and is screened with it, so wrapping the call is not a way around the gate. The
	 * documented way to open it is the `should_load_command_palette_assets` filter.
	 */
	public function test_a_call_nested_in_the_hook_is_screened_as_a_hooked_delivery() {
		set_current_screen( 'index.php' );

		add_action(
			'admin_enqueue_scripts',
			static function () {
				wp_enqueue_command_palette_assets();
			}
		);

		do_action( 'admin_enqueue_scripts', 'index.php' );

		$this->assert_palette_assets_are_registered();
		$this->assert_palette_assets_are_not_enqueued();
	}

	/**
	 * The admin guard is not filterable, so not even a direct call with the filter forced open
	 * delivers the Command Palette on a front end request.
	 */
	public function test_a_direct_call_outside_the_admin_is_not_filterable() {
		$this->assertFalse( is_admin(), 'The test should start on a front end request.' );

		add_filter( 'should_load_command_palette_assets', '__return_true' );

		wp_enqueue_command_palette_assets();

		$this->assert_palette_assets_are_not_enqueued();
	}

	/**
	 * Asserts that every Command Palette handle is registered.
	 */
	private function assert_palette_assets_are_registered() {
		foreach ( self::SCRIPT_HANDLES as $handle ) {
			$this->assertTrue( wp_script_is( $handle, 'registered' ), "Script `{$handle}` should stay registered." );
		}

		$this->assertTrue( wp_style_is( self::STYLE_HANDLE, 'registered' ), 'Style `' . self::STYLE_HANDLE . '` should stay registered.' );
	}

	/**
	 * Asserts that no Command Palette handle is enqueued.
	 */
	private function assert_palette_assets_are_not_enqueued() {
		foreach ( self::SCRIPT_HANDLES as $handle ) {
			$this->assertFalse( wp_script_is( $handle, 'enqueued' ), "Script `{$handle}` should not be enqueued." );
		}

		$this->assertFalse( wp_style_is( self::STYLE_HANDLE, 'enqueued' ), 'Style `' . self::STYLE_HANDLE . '` should not be enqueued.' );
	}
}
