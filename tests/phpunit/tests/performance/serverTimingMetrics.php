<?php

/**
 * Unit tests for the metric helpers in the performance test suite's mu-plugin.
 *
 * `wp_perf_object_cache_counters()` exists because the object cache global can be replaced
 * wholesale by an `object-cache.php` drop-in, and a drop-in is free to omit the hit and miss
 * counters, to declare them non-public, to serve them through magic accessors, or to keep
 * something other than a number in them. It is called from a `shutdown` callback that has
 * already taken the response body out of the output buffer, so a conversion notice raised
 * there would be appended to a finished response: it would corrupt the document the
 * performance suite measures and, with display errors on, leak internal detail into it.
 *
 * The performance specs run against the default cache and against the Memcached drop-in, so
 * they only ever exercise two well behaved caches, and they assert on the emitted numbers
 * rather than on what the helper does with a cache that misbehaves. Every validation branch
 * is therefore covered here instead, one cache shape at a time, checking the counters that
 * come back, that nothing was printed, that no diagnostic was raised, and that no drop-in
 * code ran.
 *
 * The mu-plugin cannot be loaded by this process. Requiring it registers callbacks that call
 * `ob_start()` and flush the buffer from `shutdown`, which is why it is kept out of
 * `src/wp-content/mu-plugins` while PHPUnit runs, and it holds the recorded bootstrap
 * duration in a static, so the value a fresh request starts from is only observable where one
 * has never been recorded. Each case is measured in a process of its own for that reason.
 *
 * @package WordPress
 * @subpackage UnitTests
 *
 * @group performance
 * @group server-timing
 */
class Tests_Performance_ServerTimingMetrics extends WP_UnitTestCase {

	/**
	 * The hooks the mu-plugin registers, in the order it registers them.
	 *
	 * Each one is registered at an extreme priority on purpose: the bootstrap duration is
	 * taken as late as possible on 'wp_loaded', the output buffer is opened as late as
	 * possible so that it wraps everything the request goes on to print, and the buffer is
	 * closed from 'shutdown' as early as possible so that nothing else can print into it.
	 *
	 * @var array[]
	 */
	const REGISTRATIONS = array(
		array(
			'hook'     => 'wp_loaded',
			'priority' => PHP_INT_MAX,
		),
		array(
			'hook'     => 'template_include',
			'priority' => PHP_INT_MAX,
		),
		array(
			'hook'     => 'admin_init',
			'priority' => PHP_INT_MAX,
		),
	);

	/**
	 * Tests that the counters are always two integers, whatever the object cache holds.
	 *
	 * The metric is emitted on every measured request, so an unusable counter has to report
	 * a number rather than be left out: a missing metric fails the specs that require it,
	 * and a non-integer one is scaled as a duration by the header loop. Reporting 0, the
	 * same value an absent counter reports, keeps the metric present and comparable.
	 *
	 * @dataProvider data_object_cache_fixtures
	 *
	 * @param string $fixture            Object cache fixture to measure.
	 * @param int    $expected_hits   Hits the fixture must produce.
	 * @param int    $expected_misses Misses the fixture must produce.
	 */
	public function test_counters_are_always_two_integers( $fixture, $expected_hits, $expected_misses ) {
		$result = $this->probe( $fixture );

		$this->assertSame(
			array( 'hits', 'misses' ),
			$result['keys'],
			'The counters must always be reported as exactly a hits and a misses entry.'
		);

		$this->assertSame(
			'integer',
			$result['hits']['type'],
			'The hits counter must be an integer, because the header loop scales anything else as a duration.'
		);

		$this->assertSame(
			'integer',
			$result['misses']['type'],
			'The misses counter must be an integer, because the header loop scales anything else as a duration.'
		);

		$this->assertSame(
			$expected_hits,
			(int) $result['hits']['value'],
			'The hits counter must be the value this object cache should produce.'
		);

		$this->assertSame(
			$expected_misses,
			(int) $result['misses']['value'],
			'The misses counter must be the value this object cache should produce.'
		);
	}

	/**
	 * Tests that reading the counters neither prints nor raises anything.
	 *
	 * This is the reason the validation exists. The helper runs after the response body has
	 * been taken out of the output buffer, so anything printed or raised here is appended to
	 * a response that is already complete.
	 *
	 * @dataProvider data_object_cache_fixtures
	 *
	 * @param string $fixture Object cache fixture to measure.
	 */
	public function test_reading_the_counters_produces_no_output_and_no_diagnostic( $fixture ) {
		$result = $this->probe( $fixture );

		$this->assertSame(
			0,
			$result['output_length'],
			'Reading the object cache counters must not print anything.'
		);

		$this->assertSame(
			array(),
			$result['diagnostics'],
			'Reading the object cache counters must not raise a warning, notice or deprecation.'
		);
	}

	/**
	 * Tests that a replacement object cache's magic accessors are never consulted.
	 *
	 * A drop-in that serves its counters through `__get()` is running its own code, inside a
	 * `shutdown` callback, at a point where it may throw or print. `get_object_vars()` is
	 * called from outside the class, so the snapshot it returns holds only genuinely public
	 * properties and no accessor is reached. The fixture's accessors would return a value no
	 * other fixture uses, and record that they ran, so this distinguishes "never consulted"
	 * from "consulted and happened to agree".
	 */
	public function test_magic_accessors_on_a_replacement_object_cache_are_never_consulted() {
		$result = $this->probe( 'magic-counters' );

		$this->assertSame(
			array(),
			$result['magic_calls'],
			'Neither __get() nor __isset() may be consulted while reading the object cache counters.'
		);

		$this->assertSame(
			0,
			(int) $result['hits']['value'],
			'A counter that only a magic accessor could serve must be reported as unavailable.'
		);

		$this->assertSame(
			0,
			(int) $result['misses']['value'],
			'A counter that only a magic accessor could serve must be reported as unavailable.'
		);
	}

	/**
	 * Tests that the bootstrap duration starts unrecorded and records what it is given.
	 *
	 * A request that never reaches 'wp_loaded' has no duration to the one boundary this
	 * metric is defined by, so it reports null rather than a placeholder. That distinction
	 * is what the 'bootstrap-valid' flag is built on: a placeholder is a number like any
	 * other by the time it reaches the results table and would be pulled into the median of
	 * the samples that were genuinely measured, while null can be flagged and dropped.
	 *
	 * Everything actually recorded is returned as a float, because the header loop scales
	 * floats to milliseconds and would emit a seconds value unscaled. A recorded 0.0 is a
	 * measurement and stays a float, which is exactly the value the unrecorded state must
	 * not be confused with.
	 */
	public function test_bootstrap_duration_starts_unrecorded_and_records_what_it_is_given() {
		$result = $this->probe( 'bootstrap-duration' );

		$expected = array(
			'initial'           => null,
			'after_float'       => 1.5,
			'reread'            => 1.5,
			'after_integer'     => 2.0,
			'after_string'      => 3.25,
			'after_zero'        => 0.0,
			'reread_after_zero' => 0.0,
		);

		$this->assertSame(
			array_keys( $expected ),
			array_keys( $result['bootstrap'] ),
			'The probe must report every step of the bootstrap duration sequence.'
		);

		foreach ( $expected as $step => $duration ) {
			if ( null === $duration ) {
				$this->assertSame(
					'NULL',
					$result['bootstrap'][ $step ]['type'],
					"The bootstrap duration must be null at the {$step} step, so that an unmeasured sample can be flagged rather than averaged in."
				);

				continue;
			}

			$this->assertSame(
				'double',
				$result['bootstrap'][ $step ]['type'],
				"The bootstrap duration must be a float at the {$step} step, because the header loop only scales floats."
			);

			$this->assertSame(
				$duration,
				(float) $result['bootstrap'][ $step ]['value'],
				"The bootstrap duration must be {$duration} at the {$step} step."
			);
		}

		$this->assertNotSame(
			$result['bootstrap']['initial']['type'],
			$result['bootstrap']['after_zero']['type'],
			'A duration that was never recorded must be distinguishable from a recorded 0.0.'
		);
	}

	/**
	 * Tests that every measurement-law field is numeric, present, and stable for a request.
	 *
	 * The metadata is emitted beside every timing sample. The OPcache flags identify the
	 * interpreter regime, the PHP version identifies its immutable interpreter build, and the
	 * process ID plus request count show whether two samples came from the same process
	 * generation. Cached-script and hit-rate values distinguish a warmed cache from a
	 * parse-dominated one without inferring the regime from timing alone.
	 */
	public function test_runtime_metadata_is_complete_numeric_and_request_stable() {
		$result = $this->probe( 'measurement-metadata' );

		$expected_keys = array(
			'opcache-enabled',
			'opcache-jit',
			'php-version-id',
			'process-id',
			'process-requests',
			'opcache-cached-scripts',
			'opcache-hit-rate',
		);

		$this->assertSame(
			$expected_keys,
			array_keys( $result['metadata']['runtime_first'] ),
			'The runtime metadata must contain every measurement-law field in its documented order.'
		);

		$this->assertSame(
			$result['metadata']['runtime_first'],
			$result['metadata']['runtime_reread'],
			'Reading runtime metadata twice in one request must not increment its process request count or change its regime.'
		);

		$this->assertSame( 0, $result['output_length'], 'Reading runtime metadata must not print anything.' );
		$this->assertSame( array(), $result['diagnostics'], 'Reading runtime metadata must not raise a diagnostic.' );

		foreach ( $expected_keys as $key ) {
			$this->assertContains(
				$result['metadata']['runtime_first'][ $key ]['type'],
				array( 'integer', 'double' ),
				"Runtime metadata {$key} must be numeric."
			);
		}

		$this->assertContains( (int) $result['metadata']['runtime_first']['opcache-enabled']['value'], array( 0, 1 ) );
		$this->assertContains( (int) $result['metadata']['runtime_first']['opcache-jit']['value'], array( 0, 1 ) );
		$this->assertSame( PHP_VERSION_ID, (int) $result['metadata']['runtime_first']['php-version-id']['value'] );
		$this->assertGreaterThan( 0, (int) $result['metadata']['runtime_first']['process-id']['value'] );
		$this->assertSame( 1, (int) $result['metadata']['runtime_first']['process-requests']['value'] );
		$this->assertGreaterThanOrEqual( 0, (int) $result['metadata']['runtime_first']['opcache-cached-scripts']['value'] );
		$this->assertGreaterThanOrEqual( 0.0, (float) $result['metadata']['runtime_first']['opcache-hit-rate']['value'] );
		$this->assertLessThanOrEqual( 100.0, (float) $result['metadata']['runtime_first']['opcache-hit-rate']['value'] );
	}

	/**
	 * Tests that OPcache status is reduced to safe, consistently typed metadata.
	 */
	public function test_opcache_status_is_normalized_without_losing_regime_values() {
		$result = $this->probe( 'measurement-metadata' );

		$enabled = $result['metadata']['opcache_enabled'];

		$this->assertSame( 'integer', $enabled['opcache-enabled']['type'] );
		$this->assertSame( 1, (int) $enabled['opcache-enabled']['value'] );
		$this->assertSame( 'integer', $enabled['opcache-jit']['type'] );
		$this->assertSame( 1, (int) $enabled['opcache-jit']['value'] );
		$this->assertSame( 'integer', $enabled['opcache-cached-scripts']['type'] );
		$this->assertSame( 321, (int) $enabled['opcache-cached-scripts']['value'] );
		$this->assertSame( 'double', $enabled['opcache-hit-rate']['type'] );
		$this->assertSame( 98.7654, (float) $enabled['opcache-hit-rate']['value'] );

		$unavailable = $result['metadata']['opcache_unavailable'];

		$this->assertSame( 0, (int) $unavailable['opcache-enabled']['value'] );
		$this->assertSame( 0, (int) $unavailable['opcache-jit']['value'] );
		$this->assertSame( 0, (int) $unavailable['opcache-cached-scripts']['value'] );
		$this->assertSame( 0.0, (float) $unavailable['opcache-hit-rate']['value'] );

		$invalid = $result['metadata']['opcache_invalid_statistics'];

		$this->assertSame( 1, (int) $invalid['opcache-enabled']['value'] );
		$this->assertSame( 0, (int) $invalid['opcache-jit']['value'] );
		$this->assertSame( 0, (int) $invalid['opcache-cached-scripts']['value'] );
		$this->assertSame( 0.0, (float) $invalid['opcache-hit-rate']['value'] );
	}

	/**
	 * Tests that a process counter increments within a generation and resets after restart.
	 */
	public function test_process_request_counter_tracks_one_process_generation() {
		$result = $this->probe( 'measurement-metadata' );

		$this->assertSame(
			array(
				'first'          => 1,
				'second'         => 2,
				'new_generation' => 1,
			),
			$result['metadata']['process_counter'],
			'The process request counter must increment for the same generation and reset when the generation changes.'
		);
	}

	/**
	 * Tests that only duration metrics are converted from seconds to milliseconds.
	 */
	public function test_server_timing_value_preserves_non_duration_floats() {
		$result = $this->probe( 'measurement-metadata' );

		$this->assertSame( 12.34, $result['metadata']['header_values']['duration'] );
		$this->assertSame( 98.7654, $result['metadata']['header_values']['hit_rate'] );
		$this->assertSame( 321, $result['metadata']['header_values']['count'] );
	}

	/**
	 * Tests that the mu-plugin registers its three hooks at the documented priorities.
	 *
	 * The priorities are the measurement boundary: moving any of them changes what the
	 * emitted metrics cover without changing any of them visibly.
	 */
	public function test_mu_plugin_registers_its_hooks_at_the_documented_priorities() {
		$result = $this->probe( 'absent' );

		$this->assertCount(
			count( self::REGISTRATIONS ),
			$result['registrations'],
			'The mu-plugin must register exactly the documented hooks.'
		);

		foreach ( self::REGISTRATIONS as $index => $registration ) {
			$this->assertSame(
				$registration['hook'],
				$result['registrations'][ $index ]['hook'],
				"Registration {$index} must be on the {$registration['hook']} hook."
			);

			$this->assertSame(
				$registration['priority'],
				$result['registrations'][ $index ]['priority'],
				"The {$registration['hook']} callback must stay at its documented priority."
			);

			$this->assertTrue(
				$result['registrations'][ $index ]['callable'],
				"The {$registration['hook']} callback must be callable."
			);
		}
	}

	/**
	 * Tests that the suite does not load the performance mu-plugin.
	 *
	 * The mu-plugin opens an output buffer on 'template_include' and 'admin_init' and closes
	 * it from 'shutdown', which makes tests that assert on output, and the AJAX tests in
	 * particular, unreliable. It belongs in `src/wp-content/mu-plugins` only while the
	 * performance specs run. If it is ever left behind, this fails and says why, instead of
	 * the failure surfacing somewhere unrelated.
	 */
	public function test_suite_does_not_load_the_performance_mu_plugin() {
		$this->assertFalse(
			function_exists( 'wp_perf_object_cache_counters' ),
			'The performance mu-plugin must not be loaded by the test suite: its output buffering makes other tests unreliable.'
		);

		$this->assertFalse(
			function_exists( 'wp_perf_bootstrap_duration' ),
			'The performance mu-plugin must not be loaded by the test suite: its output buffering makes other tests unreliable.'
		);

		$this->assertFalse(
			function_exists( 'wp_perf_runtime_metadata' ),
			'The performance mu-plugin must not be loaded by the test suite: its output buffering makes other tests unreliable.'
		);

		$this->assertFalse(
			function_exists( 'wp_perf_opcache_metadata' ),
			'The performance mu-plugin must not be loaded by the test suite: its output buffering makes other tests unreliable.'
		);

		$this->assertFalse(
			function_exists( 'wp_perf_process_generation' ),
			'The performance mu-plugin must not be loaded by the test suite: its output buffering makes other tests unreliable.'
		);

		$this->assertFalse(
			function_exists( 'wp_perf_process_request_count' ),
			'The performance mu-plugin must not be loaded by the test suite: its output buffering makes other tests unreliable.'
		);

		$this->assertFalse(
			function_exists( 'wp_perf_server_timing_value' ),
			'The performance mu-plugin must not be loaded by the test suite: its output buffering makes other tests unreliable.'
		);
	}

	/**
	 * Tests that the fixtures measured here are exactly the fixtures the probe offers.
	 *
	 * Without this, a fixture could be dropped from the probe and quietly stop being
	 * measured, or added to the probe and never be measured at all.
	 */
	public function test_every_probe_fixture_is_measured() {
		$offered = $this->probe( 'list' );

		$measured = array( 'bootstrap-duration', 'measurement-metadata' );

		foreach ( $this->data_object_cache_fixtures() as $arguments ) {
			$measured[] = $arguments[0];
		}

		sort( $offered['fixtures'] );
		sort( $measured );

		$this->assertSame(
			$offered['fixtures'],
			$measured,
			'Every object cache fixture the probe offers must be measured, and every fixture measured must exist.'
		);
	}

	/**
	 * Data provider.
	 *
	 * Covers each branch of the validation in turn. The fixtures that must report 0 hold 11
	 * and 22, or 5 and 6, so that a counter which was read anyway shows up as those numbers
	 * rather than passing by coincidence.
	 *
	 * @return array[]
	 */
	public function data_object_cache_fixtures() {
		return array(
			'no object cache at all'                    => array( 'absent', 0, 0 ),
			'an object cache global set to null'        => array( 'global-is-null', 0, 0 ),
			'an object cache global set to an array'    => array( 'global-is-array', 0, 0 ),
			'an object cache global set to a string'    => array( 'global-is-string', 0, 0 ),
			'an object cache tracking no counters'      => array( 'no-counter-properties', 0, 0 ),
			'private counters'                          => array( 'private-counters', 0, 0 ),
			'protected counters'                        => array( 'protected-counters', 0, 0 ),
			'counters behind magic accessors'           => array( 'magic-counters', 0, 0 ),
			'integer counters'                          => array( 'integer-counters', 12, 34 ),
			'counters that are still zero'              => array( 'zero-counters', 0, 0 ),
			'negative integer counters'                 => array( 'negative-integer-counters', -5, -7 ),
			'numeric string counters'                   => array( 'numeric-string-counters', 12, 34 ),
			'numeric strings with leading space'        => array( 'leading-whitespace-string-counters', 12, 34 ),
			'counters in exponent notation'             => array( 'exponent-string-counters', 1000, 2000 ),
			'counters as hexadecimal strings'           => array( 'hex-string-counters', 0, 0 ),
			'fractional float counters'                 => array( 'fractional-float-counters', 12, 34 ),
			'negative fractional float counters'        => array( 'negative-fractional-float-counters', -12, -34 ),
			'nonnumeric string counters'                => array( 'nonnumeric-string-counters', 0, 0 ),
			'empty string counters'                     => array( 'empty-string-counters', 0, 0 ),
			'boolean counters'                          => array( 'boolean-counters', 0, 0 ),
			'null counters'                             => array( 'null-counters', 0, 0 ),
			'array counters'                            => array( 'array-counters', 0, 0 ),
			'object counters'                           => array( 'object-counters', 0, 0 ),
			'infinite counters'                         => array( 'infinite-counters', 0, 0 ),
			'counters that are not a number'            => array( 'nan-counters', 0, 0 ),
			'counters above the integer range'          => array( 'out-of-range-high-counters', 0, 0 ),
			'counters below the integer range'          => array( 'out-of-range-low-counters', 0, 0 ),
			'integer counters at the top of the range'  => array( 'int-max-integer-counters', PHP_INT_MAX, PHP_INT_MAX ),
			'float counters at the top of the range'    => array( 'int-max-float-counters', 0, 0 ),
			'float counters at the bottom of the range' => array( 'int-min-float-counters', PHP_INT_MIN, PHP_INT_MIN ),
			'one usable and one unusable counter'       => array( 'mixed-counters', 12, 0 ),
		);
	}

	/**
	 * Measures one fixture in a fresh process and returns what the probe saw.
	 *
	 * Results are memoized for the run, because two tests ask about every fixture.
	 *
	 * @param string $fixture Object cache fixture to measure, or 'list' for the fixtures on offer.
	 * @return array Decoded probe result.
	 */
	private function probe( $fixture ) {
		static $results = array();

		if ( isset( $results[ $fixture ] ) ) {
			return $results[ $fixture ];
		}

		$mu_plugin = DIR_TESTROOT . '/../performance/wp-content/mu-plugins/server-timing.php';

		$this->assertFileIsReadable( $mu_plugin, 'The performance mu-plugin must be readable by the probe.' );

		$command = array(
			WP_PHP_BINARY,
			'-d',
			'error_reporting=-1',
			'-d',
			'display_errors=STDERR',
			'-d',
			'log_errors=0',
			DIR_TESTDATA . '/isolated/server-timing-probe.php',
			$mu_plugin,
			$fixture,
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

		$exit_code = proc_close( $process );

		$this->assertSame( '', $stderr, "Measuring the {$fixture} fixture must report nothing on standard error." );
		$this->assertSame( 0, $exit_code, "Measuring the {$fixture} fixture must succeed." );

		$decoded = json_decode( $stdout, true );

		$this->assertIsArray( $decoded, "The probe must report the {$fixture} fixture as a JSON object. Reported: {$stdout}" );

		if ( 'list' !== $fixture ) {
			$this->assertSame( $fixture, $decoded['fixture'], 'The probe must report on the fixture it was asked about.' );
		}

		$results[ $fixture ] = $decoded;

		return $decoded;
	}
}
