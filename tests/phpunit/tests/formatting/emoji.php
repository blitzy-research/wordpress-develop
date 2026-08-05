<?php

/**
 * @group formatting
 * @group emoji
 */
class Tests_Formatting_Emoji extends WP_UnitTestCase {

	private $png_cdn = 'https://s.w.org/images/core/emoji/17.0.2/72x72/';
	private $svg_cdn = 'https://s.w.org/images/core/emoji/17.0.2/svg/';

	/**
	 * Directories holding the Emoji data files built for a test, to remove afterwards.
	 *
	 * @var string[]
	 */
	private $emoji_fixtures = array();

	public function tear_down() {
		foreach ( $this->emoji_fixtures as $fixture ) {
			$this->remove_emoji_fixture( $fixture );
		}

		$this->emoji_fixtures = array();

		parent::tear_down();
	}

	/**
	 * @ticket 63842
	 *
	 * @covers ::_print_emoji_detection_script
	 */
	public function test_script_tag_printing() {
		// `_print_emoji_detection_script()` assumes `wp-includes/js/wp-emoji-loader.js` is present:
		self::touch( ABSPATH . WPINC . '/js/wp-emoji-loader.js' );
		$output = get_echo( '_print_emoji_detection_script' );

		$processor = new WP_HTML_Tag_Processor( $output );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame( 'SCRIPT', $processor->get_tag() );
		$this->assertSame( 'wp-emoji-settings', $processor->get_attribute( 'id' ) );
		$this->assertSame( 'application/json', $processor->get_attribute( 'type' ) );
		$text     = $processor->get_modifiable_text();
		$settings = json_decode( $text, true );
		$this->assertIsArray( $settings );

		$this->assertEqualSets(
			array( 'baseUrl', 'ext', 'svgUrl', 'svgExt', 'source' ),
			array_keys( $settings )
		);
		$this->assertSame( $this->png_cdn, $settings['baseUrl'] );
		$this->assertSame( '.png', $settings['ext'] );
		$this->assertSame( $this->svg_cdn, $settings['svgUrl'] );
		$this->assertSame( '.svg', $settings['svgExt'] );
		$this->assertIsArray( $settings['source'] );
		$this->assertArrayHasKey( 'wpemoji', $settings['source'] );
		$this->assertArrayHasKey( 'twemoji', $settings['source'] );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame( 'SCRIPT', $processor->get_tag() );
		$this->assertSame( 'module', $processor->get_attribute( 'type' ) );
		$this->assertNull( $processor->get_attribute( 'src' ) );
		$this->assertFalse( $processor->next_tag() );
	}

	/**
	 * @ticket 36525
	 *
	 * @covers ::_print_emoji_detection_script
	 */
	public function test_unfiltered_emoji_cdns() {
		// `_print_emoji_detection_script()` assumes `wp-includes/js/wp-emoji-loader.js` is present:
		self::touch( ABSPATH . WPINC . '/js/wp-emoji-loader.js' );
		$output = get_echo( '_print_emoji_detection_script' );

		$this->assertStringContainsString( wp_json_encode( $this->png_cdn, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ), $output );
		$this->assertStringContainsString( wp_json_encode( $this->svg_cdn, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ), $output );
	}

	public function _filtered_emoji_svg_cdn( $cdn = '' ) {
		return 'https://s.wordpress.org/images/core/emoji/svg/';
	}

	/**
	 * @ticket 36525
	 *
	 * @covers ::_print_emoji_detection_script
	 */
	public function test_filtered_emoji_svn_cdn() {
		$filtered_svn_cdn = $this->_filtered_emoji_svg_cdn();

		add_filter( 'emoji_svg_url', array( $this, '_filtered_emoji_svg_cdn' ) );

		// `_print_emoji_detection_script()` assumes `wp-includes/js/wp-emoji-loader.js` is present:
		self::touch( ABSPATH . WPINC . '/js/wp-emoji-loader.js' );
		$output = get_echo( '_print_emoji_detection_script' );

		$this->assertStringContainsString( wp_json_encode( $this->png_cdn, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ), $output );
		$this->assertStringNotContainsString( wp_json_encode( $this->svg_cdn, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ), $output );
		$this->assertStringContainsString( wp_json_encode( $filtered_svn_cdn, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ), $output );

		remove_filter( 'emoji_svg_url', array( $this, '_filtered_emoji_svg_cdn' ) );
	}

	public function _filtered_emoji_png_cdn( $cdn = '' ) {
		return 'https://s.wordpress.org/images/core/emoji/png_cdn/';
	}

	/**
	 * @ticket 36525
	 *
	 * @covers ::_print_emoji_detection_script
	 */
	public function test_filtered_emoji_png_cdn() {
		$filtered_png_cdn = $this->_filtered_emoji_png_cdn();

		add_filter( 'emoji_url', array( $this, '_filtered_emoji_png_cdn' ) );

		// `_print_emoji_detection_script()` assumes `wp-includes/js/wp-emoji-loader.js` is present:
		self::touch( ABSPATH . WPINC . '/js/wp-emoji-loader.js' );
		$output = get_echo( '_print_emoji_detection_script' );

		$this->assertStringContainsString( wp_json_encode( $filtered_png_cdn, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ), $output );
		$this->assertStringNotContainsString( wp_json_encode( $this->png_cdn, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ), $output );
		$this->assertStringContainsString( wp_json_encode( $this->svg_cdn, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ), $output );

		remove_filter( 'emoji_url', array( $this, '_filtered_emoji_png_cdn' ) );
	}

	/**
	 * @ticket 41501
	 *
	 * @covers ::_wp_emoji_list
	 */
	public function test_wp_emoji_list_returns_data() {
		$default = _wp_emoji_list();
		$this->assertNotEmpty( $default, 'Default should not be empty' );

		$entities = _wp_emoji_list( 'entities' );
		$this->assertNotEmpty( $entities, 'Entities should not be empty' );
		$this->assertIsArray( $entities, 'Entities should be an array' );
		// Emoji 17 contains 4007 entities, this number will only increase.
		$this->assertGreaterThanOrEqual( 4007, count( $entities ), 'Entities should contain at least 4007 items' );
		$this->assertSame( $default, $entities, 'Entities should be returned by default' );

		$partials = _wp_emoji_list( 'partials' );
		$this->assertNotEmpty( $partials, 'Partials should not be empty' );
		$this->assertIsArray( $partials, 'Partials should be an array' );
		// Emoji 17 contains 1438 partials, this number will only increase.
		$this->assertGreaterThanOrEqual( 1438, count( $partials ), 'Partials should contain at least 1438 items' );

		$this->assertNotSame( $default, $partials );
	}

	public function data_wp_encode_emoji() {
		return array(
			array(
				// Not emoji.
				'’',
				'’',
			),
			array(
				// Simple emoji.
				'🙂',
				'&#x1f642;',
			),
			array(
				// Bird, ZWJ, black large square, emoji selector.
				'🐦‍⬛',
				'&#x1f426;&#x200d;&#x2b1b;',
			),
			array(
				// Unicode 10.
				'🧚',
				'&#x1f9da;',
			),
			array(
				// Hairy creature (Unicode 17).
				'🫈',
				'&#x1fac8;',
			),
		);
	}

	/**
	 * @ticket 35293
	 * @dataProvider data_wp_encode_emoji
	 *
	 * @covers ::wp_encode_emoji
	 */
	public function test_wp_encode_emoji( $emoji, $expected ) {
		$this->assertSame( $expected, wp_encode_emoji( $emoji ) );
	}

	public function data_wp_staticize_emoji() {
		$data = array(
			array(
				// Not emoji.
				'’',
				'’',
			),
			array(
				// Simple emoji.
				'🙂',
				'<img src="' . $this->png_cdn . '1f642.png" alt="🙂" class="wp-smiley" style="height: 1em; max-height: 1em;" />',
			),
			array(
				// Skin tone, gender, ZWJ, emoji selector.
				'👮🏼‍♀️',
				'<img src="' . $this->png_cdn . '1f46e-1f3fc-200d-2640-fe0f.png" alt="👮🏼‍♀️" class="wp-smiley" style="height: 1em; max-height: 1em;" />',
			),
			array(
				// Unicode 10.
				'🧚',
				'<img src="' . $this->png_cdn . '1f9da.png" alt="🧚" class="wp-smiley" style="height: 1em; max-height: 1em;" />',
			),
			array(
				// Hairy creature (Unicode 17).
				'🫈',
				'<img src="' . $this->png_cdn . '1fac8.png" alt="🫈" class="wp-smiley" style="height: 1em; max-height: 1em;" />',
			),
		);

		return $data;
	}

	/**
	 * @ticket 35293
	 * @dataProvider data_wp_staticize_emoji
	 *
	 * @covers ::wp_staticize_emoji
	 */
	public function test_wp_staticize_emoji( $emoji, $expected ) {
		$this->assertSame( $expected, wp_staticize_emoji( $emoji ) );
	}

	/**
	 * Tests that the detection script is not printed unless something asks for it.
	 *
	 * The script is a polyfill for browsers that cannot render the emoji WordPress
	 * recognises, and it costs an inline settings object plus the whole emoji loader read
	 * from disk on every page view. Skipping it by default is the saving, so the default is
	 * asserted on its own rather than only through what it causes.
	 *
	 * @covers ::wp_should_load_emoji_detection_script
	 */
	public function test_emoji_detection_script_is_not_loaded_by_default() {
		$this->assertFalse(
			wp_should_load_emoji_detection_script(),
			'The Emoji detection script must not be printed unless it is asked for.'
		);
	}

	/**
	 * Tests that the filter can ask for the script again.
	 *
	 * This is the documented way back to the behavior from before the script was gated, so
	 * it has to keep working for anyone who needs the polyfill.
	 *
	 * @covers ::wp_should_load_emoji_detection_script
	 */
	public function test_should_load_emoji_detection_script_filter_can_ask_for_the_script() {
		add_filter( 'should_load_emoji_detection_script', '__return_true' );

		$this->assertTrue(
			wp_should_load_emoji_detection_script(),
			'Returning true from the filter must ask for the Emoji detection script.'
		);
	}

	/**
	 * Tests that the filter is honoured when it agrees with the default.
	 *
	 * A gate that ignored a callback returning false would look correct while it was closed
	 * and be wrong the moment anything else opened it.
	 *
	 * @covers ::wp_should_load_emoji_detection_script
	 */
	public function test_should_load_emoji_detection_script_filter_can_decline_the_script() {
		add_filter( 'should_load_emoji_detection_script', '__return_true', 5 );
		add_filter( 'should_load_emoji_detection_script', '__return_false', 20 );

		$this->assertFalse(
			wp_should_load_emoji_detection_script(),
			'Returning false from the filter must decline the Emoji detection script.'
		);
	}

	/**
	 * Tests the value the filter is handed.
	 *
	 * A callback that flips the flag it is given, rather than returning a fixed value, only
	 * behaves the documented way if the flag it receives is the documented default.
	 *
	 * @covers ::wp_should_load_emoji_detection_script
	 */
	public function test_should_load_emoji_detection_script_filter_receives_the_default() {
		$received = 'nothing';

		add_filter(
			'should_load_emoji_detection_script',
			static function ( $should_load ) use ( &$received ) {
				$received = $should_load;
				return $should_load;
			}
		);

		wp_should_load_emoji_detection_script();

		$this->assertFalse( $received, 'The filter must be handed the default value of the flag.' );
	}

	/**
	 * Tests that the front-end registrations are unchanged.
	 *
	 * Gating the script must not have moved or dropped the hooks it is printed from: a
	 * plugin removing either registration, at either priority, has to keep working.
	 *
	 * @covers ::print_emoji_detection_script
	 */
	public function test_emoji_detection_script_keeps_its_front_end_registrations() {
		$this->assertSame(
			7,
			has_action( 'wp_head', 'print_emoji_detection_script' ),
			'The Emoji detection script must still be printed from wp_head at priority 7.'
		);

		$this->assertSame(
			10,
			has_action( 'embed_head', 'print_emoji_detection_script' ),
			'The Emoji detection script must still be printed from embed_head at priority 10.'
		);
	}

	/**
	 * Tests that a closed gate prints nothing and hooks nothing.
	 *
	 * The gate is checked before the static that remembers the script was printed, so this
	 * is safe to call whatever else has run first in this process.
	 *
	 * @covers ::print_emoji_detection_script
	 */
	public function test_print_emoji_detection_script_does_nothing_while_the_gate_is_closed() {
		remove_all_filters( 'should_load_emoji_detection_script' );

		$this->assertSame(
			'',
			get_echo( 'print_emoji_detection_script' ),
			'Nothing may be printed while the Emoji detection script is not asked for.'
		);

		$this->assertFalse(
			has_action( 'wp_print_footer_scripts', '_print_emoji_detection_script' ),
			'Nothing may be hooked onto the footer while the Emoji detection script is not asked for.'
		);
	}

	/**
	 * Tests that a fresh request starts with the gate closed.
	 *
	 * @covers ::wp_should_load_emoji_detection_script
	 */
	public function test_fresh_request_starts_with_the_gate_closed() {
		$result = $this->probe_emoji_detection( 'gate-default' );

		$this->assertFalse( $result['gate'], 'A fresh request must not ask for the Emoji detection script.' );
		$this->assertFalse( $result['filtered_value'], 'The filter must be handed false on a fresh request.' );
		$this->assertFalse(
			$result['worker_priority_after'],
			'A fresh request must not hook the Emoji detection script onto the footer.'
		);
	}

	/**
	 * Tests that a closed gate leaves nothing behind, even on a request of its own.
	 *
	 * @covers ::print_emoji_detection_script
	 */
	public function test_closed_gate_neither_prints_nor_hooks_on_a_fresh_request() {
		$result = $this->probe_emoji_detection( 'closed' );

		$this->assertSame( '', $result['output'], 'A closed gate must print nothing.' );
		$this->assertFalse( $result['worker_priority_after'], 'A closed gate must hook nothing onto the footer.' );
	}

	/**
	 * Tests that an open gate defers the script to the footer.
	 *
	 * The script is not printed where the hook fires. It is handed to
	 * `wp_print_footer_scripts`, so that the loader is at the end of the document, and it
	 * has to arrive there exactly once.
	 *
	 * @covers ::print_emoji_detection_script
	 */
	public function test_open_gate_defers_the_script_to_the_footer() {
		$result = $this->probe_emoji_detection( 'open' );

		$this->assertTrue( $result['gate'], 'The probe must have asked for the Emoji detection script.' );
		$this->assertSame( '', $result['output'], 'Nothing may be printed where the script is asked for.' );

		$this->assertSame(
			10,
			$result['worker_priority_after'],
			'The Emoji detection script must be hooked onto the footer at priority 10.'
		);

		$this->assertSame(
			1,
			$result['settings_printed'],
			'The footer must print the Emoji settings exactly once.'
		);
	}

	/**
	 * Tests that the script is printed inline when the footer has already gone out.
	 *
	 * There is nothing left to defer to at that point, so deferring would drop the script
	 * altogether.
	 *
	 * @covers ::print_emoji_detection_script
	 */
	public function test_open_gate_prints_inline_once_the_footer_has_been_printed() {
		$result = $this->probe_emoji_detection( 'open-after-footer' );

		$this->assertSame(
			1,
			$result['settings_printed'],
			'The Emoji settings must be printed inline exactly once when the footer has already been printed.'
		);

		$this->assertFalse(
			$result['worker_priority_after'],
			'Nothing may be hooked onto a footer that has already been printed.'
		);
	}

	/**
	 * Tests that the script is printed at most once per request.
	 *
	 * Both hooked contexts can fire in one request, and a plugin may call the function as
	 * well, so the function remembers that it has printed. Once the footer has gone out, a
	 * call that got past that would print the whole loader a second time, which is the only
	 * place the guarantee is visible in the document.
	 *
	 * @covers ::print_emoji_detection_script
	 */
	public function test_script_is_printed_at_most_once_per_request() {
		$result = $this->probe_emoji_detection( 'open-repeat-after-footer' );

		$this->assertSame(
			1,
			$result['settings_printed'],
			'The footer must print the Emoji settings exactly once.'
		);

		$this->assertSame(
			0,
			$result['settings_reprinted'],
			'The Emoji settings must not be printed a second time in the same request.'
		);

		$this->assertSame(
			'',
			$result['output'],
			'A repeat call must print nothing at all once the script has been printed.'
		);
	}

	/**
	 * Tests that a call made while the gate is closed does not use up the one chance.
	 *
	 * The gate is checked before the static, so a closed call has to leave the request able
	 * to print the script later. Checking them the other way round would silence the script
	 * for the whole request as soon as any hooked context fired with the gate closed, which
	 * is what happens on every page before a plugin opens it on a later hook.
	 *
	 * @covers ::print_emoji_detection_script
	 */
	public function test_closed_call_does_not_use_up_the_one_chance_to_print() {
		$result = $this->probe_emoji_detection( 'closed-then-open' );

		$this->assertSame(
			10,
			$result['worker_priority_after'],
			'A call made after the gate opened must still hook the Emoji detection script onto the footer.'
		);

		$this->assertSame(
			1,
			$result['settings_printed'],
			'The Emoji settings must still be printed once, even though an earlier call found the gate closed.'
		);
	}

	/**
	 * Tests that the head prints no Emoji script while the gate is closed.
	 *
	 * This is the saving as it appears in the document, measured through the hook the script
	 * is really printed from rather than by calling the function directly.
	 *
	 * @covers ::print_emoji_detection_script
	 */
	public function test_wp_head_prints_no_emoji_script_while_the_gate_is_closed() {
		$result = $this->probe_emoji_detection( 'wp-head-closed' );

		$this->assertNotSame(
			'',
			$result['output'],
			'The probe must have printed the head, otherwise it proves nothing about the Emoji script.'
		);

		$this->assertSame(
			0,
			$result['settings_printed'],
			'A page must carry no Emoji settings while the detection script is not asked for.'
		);

		$this->assertStringNotContainsString(
			'wp-emoji-loader',
			$result['output'],
			'A page must not carry the Emoji loader while the detection script is not asked for.'
		);
	}

	/**
	 * Tests that the head prints the Emoji script once when it is asked for.
	 *
	 * @dataProvider data_hooked_contexts_that_print_the_emoji_script
	 *
	 * @param string $scenario Probe scenario to run.
	 *
	 * @covers ::print_emoji_detection_script
	 */
	public function test_hooked_context_prints_the_emoji_script_once_when_it_is_asked_for( $scenario ) {
		$result = $this->probe_emoji_detection( $scenario );

		$this->assertSame(
			1,
			$result['settings_printed'],
			'A page must carry the Emoji settings exactly once when the detection script is asked for.'
		);

		$this->assertStringContainsString(
			'wp-emoji-loader',
			$result['output'],
			'A page must carry the Emoji loader when the detection script is asked for.'
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_hooked_contexts_that_print_the_emoji_script() {
		return array(
			'the document head' => array( 'wp-head-open' ),
			'an embed head'     => array( 'embed-head-open' ),
		);
	}

	/**
	 * Tests that the admin registration is unchanged and can still be removed.
	 *
	 * Core opts a single screen out of the script with a `remove_action()` call, in
	 * wp-admin/edit-form-blocks.php, and plugins do the same. Putting a gate inside the
	 * function keeps the registration where it was, so that opt-out has to keep working.
	 *
	 * @covers ::print_emoji_detection_script
	 */
	public function test_admin_registration_is_unchanged_and_can_still_be_removed() {
		$result = $this->probe_emoji_detection( 'admin-registration' );

		$this->assertSame(
			10,
			$result['admin_scripts_priority'],
			'The Emoji detection script must still be printed from admin_print_scripts at priority 10.'
		);

		$this->assertFalse(
			$result['admin_scripts_after_removal'],
			'Removing the admin registration must still take the Emoji detection script off that hook.'
		);
	}

	/**
	 * Tests that a data file that is not what it should be degrades to empty arrays.
	 *
	 * Callers iterate over the return value directly, so the function has to hand back an
	 * array whatever it finds on disk. None of these cases can be arranged against the
	 * committed data file, so each is measured in a process of its own, against a data file
	 * built for it.
	 *
	 * @dataProvider data_emoji_data_files
	 *
	 * @param string   $data_file Contents of the Emoji data file, or an empty string for none.
	 * @param int      $entities  How many entities are expected.
	 * @param int      $partials  How many partials are expected.
	 *
	 * @covers ::_wp_emoji_list
	 */
	public function test_wp_emoji_list_always_returns_arrays( $data_file, $entities, $partials ) {
		$result = $this->probe_emoji_list( $data_file );

		$this->assertSame( 'array', $result['default_type'], '_wp_emoji_list() must return an array by default.' );
		$this->assertSame( 'array', $result['entities_type'], '_wp_emoji_list() must return an array of entities.' );
		$this->assertSame( 'array', $result['partials_type'], '_wp_emoji_list() must return an array of partials.' );
		$this->assertSame( 'array', $result['unknown_type'], '_wp_emoji_list() must return an array for an unrecognised type.' );

		$this->assertSame( $entities, $result['entities_count'], 'The entities must be read from the data file, or left empty.' );
		$this->assertSame( $partials, $result['partials_count'], 'The partials must be read from the data file, or left empty.' );

		$this->assertTrue( $result['default_is_entities'], 'The entities must be what _wp_emoji_list() returns by default.' );
		$this->assertTrue( $result['unknown_is_partials'], 'An unrecognised type must fall through to the partials.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_emoji_data_files() {
		return array(
			'a data file with both lists'     => array(
				'<?php' . "\n" . 'return array( \'entities\' => array( \'&#x1f600;\', \'&#x1f601;\' ), \'partials\' => array( \'&#x1f600;\' ) );' . "\n",
				2,
				1,
			),
			'no data file at all'             => array( '', 0, 0 ),
			'a data file returning no array'  => array( '<?php' . "\n" . 'return 42;' . "\n", 0, 0 ),
			'a data file returning null'      => array( '<?php' . "\n" . 'return null;' . "\n", 0, 0 ),
			'a data file returning nothing'   => array( '<?php' . "\n" . '$unused = 1;' . "\n", 0, 0 ),
			'a data file with entities only'  => array(
				'<?php' . "\n" . 'return array( \'entities\' => array( \'a\', \'b\', \'c\' ) );' . "\n",
				3,
				0,
			),
			'a data file with partials only'  => array(
				'<?php' . "\n" . 'return array( \'partials\' => array( \'a\', \'b\' ) );' . "\n",
				0,
				2,
			),
			'a data file whose lists are not' => array(
				'<?php' . "\n" . 'return array( \'entities\' => \'nope\', \'partials\' => 7 );' . "\n",
				0,
				0,
			),
			'a data file with one list wrong' => array(
				'<?php' . "\n" . 'return array( \'entities\' => array( \'a\', \'b\' ), \'partials\' => \'not-an-array\' );' . "\n",
				2,
				0,
			),
		);
	}

	/**
	 * Tests that a data file that cannot be parsed fails loudly.
	 *
	 * Degrading to empty arrays is right for a data file that is readable and wrong shaped.
	 * A data file that is not valid PHP is a broken installation, and it must not be
	 * mistaken for an installation with no emoji.
	 *
	 * @covers ::_wp_emoji_list
	 */
	public function test_wp_emoji_list_fails_loudly_when_the_data_file_cannot_be_parsed() {
		$probe = $this->run_emoji_list_probe( '<?php' . "\n" . 'return array( /* never closed' . "\n" );

		$this->assertNotSame( 0, $probe['exit_code'], 'A data file that is not valid PHP must fail rather than be worked around.' );
		$this->assertStringContainsString( 'Parse error', $probe['stderr'], 'A data file that is not valid PHP must say so.' );
		$this->assertSame( '', $probe['stdout'], 'A data file that is not valid PHP must not produce a result.' );
	}

	/**
	 * Loads WordPress in a fresh process and reports what the Emoji script did.
	 *
	 * @param string $scenario Probe scenario to run.
	 * @return array Decoded probe result.
	 */
	private function probe_emoji_detection( $scenario ) {
		static $results = array();

		if ( isset( $results[ $scenario ] ) ) {
			return $results[ $scenario ];
		}

		$configuration = defined( 'WP_TESTS_CONFIG_FILE_PATH' )
			? WP_TESTS_CONFIG_FILE_PATH
			: dirname( ABSPATH ) . '/wp-tests-config.php';

		$this->assertFileIsReadable( $configuration, 'The test configuration must be readable by the probe.' );

		$arguments = array( $configuration, $scenario );

		foreach ( array( 'MULTISITE', 'SUBDOMAIN_INSTALL', 'DOMAIN_CURRENT_SITE', 'PATH_CURRENT_SITE', 'SITE_ID_CURRENT_SITE', 'BLOG_ID_CURRENT_SITE' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				continue;
			}

			$value = constant( $constant );

			$arguments[] = $constant . '=' . ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : $value );
		}

		$probe = $this->run_probe( DIR_TESTDATA . '/isolated/emoji-detection-probe.php', $arguments );

		$this->assertSame( '', $probe['stderr'], "The {$scenario} scenario must report nothing on standard error." );
		$this->assertSame( 0, $probe['exit_code'], "The {$scenario} scenario must succeed." );

		$decoded = json_decode( $probe['stdout'], true );

		$this->assertIsArray( $decoded, "The {$scenario} scenario must report a JSON object. Reported: {$probe['stdout']}" );
		$this->assertSame( $scenario, $decoded['scenario'], 'The probe must report on the scenario it was asked about.' );

		$results[ $scenario ] = $decoded;

		return $decoded;
	}

	/**
	 * Reads the Emoji lists in a fresh process, from a data file built for the test.
	 *
	 * @param string $data_file Contents of the Emoji data file, or an empty string for none.
	 * @return array Decoded probe result.
	 */
	private function probe_emoji_list( $data_file ) {
		$probe = $this->run_emoji_list_probe( $data_file );

		$this->assertSame( '', $probe['stderr'], 'Reading the Emoji lists must report nothing on standard error.' );
		$this->assertSame( 0, $probe['exit_code'], 'Reading the Emoji lists must succeed.' );

		$decoded = json_decode( $probe['stdout'], true );

		$this->assertIsArray( $decoded, "The probe must report a JSON object. Reported: {$probe['stdout']}" );
		$this->assertSame( '' !== $data_file, $decoded['file_exists'], 'The probe must read the data file the test built for it.' );

		return $decoded;
	}

	/**
	 * Runs the Emoji list probe against a data file built for the test.
	 *
	 * The data file is written below the temporary directory, and the probe is pointed at
	 * it with its own ABSPATH, so the committed data file is never touched.
	 *
	 * @param string $data_file Contents of the Emoji data file, or an empty string for none.
	 * @return array Standard output, standard error and the exit code.
	 */
	private function run_emoji_list_probe( $data_file ) {
		$fixture = get_temp_dir() . uniqid( 'wp-emoji-arrays-' ) . '/';

		$this->emoji_fixtures[] = $fixture;

		$this->assertTrue( mkdir( $fixture . 'wp-includes', 0777, true ), 'The Emoji data file directory must be created.' );

		if ( '' !== $data_file ) {
			$this->assertNotFalse(
				file_put_contents( $fixture . 'wp-includes/emoji-arrays.php', $data_file ),
				'The Emoji data file must be written.'
			);
		}

		return $this->run_probe(
			DIR_TESTDATA . '/isolated/emoji-list-probe.php',
			array( ABSPATH . WPINC . '/formatting.php', $fixture )
		);
	}

	/**
	 * Removes a directory an Emoji data file was built in.
	 *
	 * @param string $fixture Directory to remove.
	 */
	private function remove_emoji_fixture( $fixture ) {
		if ( ! is_dir( $fixture ) ) {
			return;
		}

		$data_file = $fixture . 'wp-includes/emoji-arrays.php';

		if ( file_exists( $data_file ) ) {
			unlink( $data_file );
		}

		if ( is_dir( $fixture . 'wp-includes' ) ) {
			rmdir( $fixture . 'wp-includes' );
		}

		rmdir( $fixture );
	}

	/**
	 * Runs a probe in a fresh process and returns what it reported.
	 *
	 * @param string   $probe     Probe to run.
	 * @param string[] $arguments Arguments to pass to it.
	 * @return array Standard output, standard error and the exit code.
	 */
	private function run_probe( $probe, array $arguments ) {
		$command = array_merge(
			array(
				WP_PHP_BINARY,
				'-d',
				'error_reporting=-1',
				'-d',
				'display_errors=STDERR',
				'-d',
				'log_errors=0',
				$probe,
			),
			$arguments
		);

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( $command, $descriptors, $pipes );

		$this->assertIsResource( $process, 'The probe process must start.' );

		// The probe reads nothing, and an open pipe would keep it waiting for input.
		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'stdout'    => $stdout,
			'stderr'    => $stderr,
			'exit_code' => proc_close( $process ),
		);
	}
}
