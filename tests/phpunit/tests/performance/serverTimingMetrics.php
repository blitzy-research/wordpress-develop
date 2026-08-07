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
	 * The cache reset answers 'plugins_loaded' at priority 1, early enough that nothing the
	 * request would otherwise be served from has been read yet, and matching the priority
	 * the pre-existing clear-cache.php uses so that either file alone behaves identically.
	 * The other three are registered at an extreme priority on purpose: the bootstrap
	 * duration is taken as late as possible on 'wp_loaded', the output buffer is opened as
	 * late as possible so that it wraps everything the request goes on to print, and the
	 * buffer is closed from 'shutdown' as early as possible so that nothing else can print
	 * into it.
	 *
	 * @var array[]
	 */
	const REGISTRATIONS = array(
		array(
			'hook'     => 'plugins_loaded',
			'priority' => 1,
		),
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
	 * The metadata is emitted beside every timing sample, because opcode-cache state moves
	 * measured time and memory by far more than anything under test, so a figure reported
	 * without its regime is not comparable to another. The two flags are the whole of what is
	 * reported: this header reaches every client, so a process ID, an interpreter version, a
	 * worker request count and server-wide OPcache counters describe the host rather than the
	 * request and are deliberately absent.
	 */
	public function test_runtime_metadata_is_complete_numeric_and_request_stable() {
		$result = $this->probe( 'measurement-metadata' );

		$expected_keys = array(
			'opcache-enabled',
			'opcache-jit',
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
	}

	/**
	 * Tests that nothing identifying the host or the interpreter is reported.
	 *
	 * The Server-Timing header is sent to every client, including an unauthenticated one, so
	 * a metric that says which process answered, which interpreter build ran it, how many
	 * requests that worker has served or how many scripts the shared opcode cache holds
	 * describes the host rather than the request under measurement. Each of these was emitted
	 * once and was removed; naming them here is what stops one being reintroduced as a
	 * convenience.
	 */
	public function test_runtime_metadata_reports_nothing_that_identifies_the_host() {
		$result = $this->probe( 'measurement-metadata' );

		foreach ( array( 'runtime_first', 'opcache_configured_on', 'opcache_unavailable' ) as $set ) {
			foreach ( array( 'php-version-id', 'process-id', 'process-requests', 'opcache-cached-scripts', 'opcache-hit-rate' ) as $disclosed ) {
				$this->assertArrayNotHasKey(
					$disclosed,
					$result['metadata'][ $set ],
					"The {$set} metadata must not report {$disclosed}, which describes the host rather than the measured request."
				);
			}
		}

		foreach (
			array(
				'wp_perf_process_generation',
				'wp_perf_process_request_count',
				'wp_perf_process_owner_id',
				'wp_perf_process_counter_directory',
				'wp_perf_process_counter_handle',
			) as $removed
		) {
			$this->assertNotContains(
				$removed,
				$result['functions'],
				"{$removed}() backed a metric that described the host and was removed; the mu-plugin must not declare it again."
			);
		}
	}

	/**
	 * Tests that the mu-plugin declares exactly the helpers the measurement needs.
	 *
	 * A helper that stops being declared takes its metric with it, and one that is added back
	 * brings a metric back with it. Pinning the set is what makes either visible.
	 */
	public function test_mu_plugin_declares_exactly_the_documented_helpers() {
		$result = $this->probe( 'measurement-metadata' );

		$expected = array(
			'wp_perf_bootstrap_duration',
			'wp_perf_cache_reset_status',
			'wp_perf_cache_reset_token',
			'wp_perf_object_cache_counters',
			'wp_perf_opcache_directive',
			'wp_perf_opcache_flag',
			'wp_perf_opcache_metadata',
			'wp_perf_reset_caches',
			'wp_perf_runtime_metadata',
			'wp_perf_server_timing_value',
		);

		$declared = $result['functions'];
		sort( $declared );

		$this->assertSame(
			$expected,
			$declared,
			'The mu-plugin must declare exactly the documented measurement helpers.'
		);
	}

	/**
	 * Tests that the regime is read from the interpreter flags rather than from the OPcache status.
	 *
	 * Holding those two apart is what makes the regime immutable across the iterations of one
	 * measured scenario, and it is the reason this suite can compare a before and an after arm
	 * at all. The performance mu-plugin clear-cache.php resets the opcode cache before every
	 * measured iteration; a reset is only performed once a request can take the lock with no
	 * other worker active, and every request arriving before that reports an inactive
	 * accelerator even though the interpreter flags never moved. Measured on this suite's harness,
	 * that happened in none of 40 serial reset-then-request cycles and in 56 of 60 of the same
	 * cycles run against six concurrent front-page requests. A regime read from the status
	 * therefore changes between iterations of one scenario, where the interpreter flags cannot
	 * change without restarting the process.
	 */
	public function test_opcache_regime_comes_from_configuration_rather_than_status() {
		$result = $this->probe( 'measurement-metadata' );

		$configured_on = $result['metadata']['opcache_configured_on'];

		$this->assertSame( 'integer', $configured_on['opcache-enabled']['type'] );
		$this->assertSame( 1, (int) $configured_on['opcache-enabled']['value'] );
		$this->assertSame( 'integer', $configured_on['opcache-jit']['type'] );
		$this->assertSame( 1, (int) $configured_on['opcache-jit']['value'] );

		$this->assertSame(
			array( 'opcache-enabled', 'opcache-jit' ),
			array_keys( $configured_on ),
			'Nothing beyond the two regime flags may reach the metadata: a server-wide counter would describe the host rather than the measured request.'
		);

		$this->assertSame(
			$configured_on,
			$result['metadata']['opcache_accelerator_inactive'],
			'An inactive accelerator must change nothing: the reset that made it inactive leaves the interpreter flags exactly as they were.'
		);

		$configured_off = $result['metadata']['opcache_configured_off'];

		$this->assertSame(
			0,
			(int) $configured_off['opcache-enabled']['value'],
			'A disabled opcache.enable must report a disabled regime however the status describes itself.'
		);
		$this->assertSame( 0, (int) $configured_off['opcache-jit']['value'], 'JIT cannot be on while the opcode cache is off.' );

		$jit_off = $result['metadata']['opcache_jit_off'];

		$this->assertSame( 1, (int) $jit_off['opcache-enabled']['value'] );
		$this->assertSame( 0, (int) $jit_off['opcache-jit']['value'], 'A disabling opcache.jit mode must report JIT off.' );

		$jit_without_buffer = $result['metadata']['opcache_jit_without_buffer'];

		$this->assertSame( 1, (int) $jit_without_buffer['opcache-enabled']['value'] );
		$this->assertSame(
			0,
			(int) $jit_without_buffer['opcache-jit']['value'],
			'A compiling JIT mode with no buffer to compile into must report JIT off.'
		);

		$unavailable = $result['metadata']['opcache_unavailable'];

		$this->assertSame( 0, (int) $unavailable['opcache-enabled']['value'] );
		$this->assertSame( 0, (int) $unavailable['opcache-jit']['value'] );

		$this->assertSame( 0, $result['output_length'], 'Reading the OPcache regime must not print anything.' );
		$this->assertSame( array(), $result['diagnostics'], 'Reading the OPcache regime must not raise a diagnostic.' );
	}

	/**
	 * Tests that a directive is read from the configuration snapshot and otherwise from ini_get().
	 *
	 * opcache_get_configuration() reports the value the engine resolved, which makes it the
	 * accurate source, and opcache.restrict_api can refuse it exactly as it refuses
	 * opcache_get_status(). ini_get() is never refused, so falling through to it is what keeps
	 * a restricted request from reporting an unknown regime.
	 */
	public function test_opcache_directives_are_read_from_the_snapshot_then_from_ini() {
		$result     = $this->probe( 'measurement-metadata' );
		$directives = $result['metadata']['opcache_directive'];

		$this->assertSame( 'string', $directives['from_snapshot']['type'] );
		$this->assertSame(
			'off',
			$directives['from_snapshot']['value'],
			'A snapshot that carries the directive must be preferred over the ini value.'
		);

		$this->assertSame(
			$directives['ini_value'],
			$directives['from_ini'],
			'With no snapshot at all the directive must come from ini_get().'
		);

		$this->assertSame(
			$directives['jit_ini_value'],
			$directives['name_absent'],
			'A snapshot that does not carry the directive must fall through to ini_get() rather than report it unset.'
		);

		$this->assertSame( 'boolean', $directives['unknown_name']['type'] );
		$this->assertSame(
			'',
			$directives['unknown_name']['value'],
			'An unknown directive must report false, and reporting it must raise no diagnostic.'
		);
	}

	/**
	 * Tests that every boolean form either configuration source can answer with is normalized.
	 *
	 * opcache_get_configuration() types a boolean directive as a boolean while ini_get() types
	 * it as a string whose spelling differs between builds, so the regime would depend on which
	 * source answered unless both are normalized the same way.
	 */
	public function test_opcache_directive_booleans_are_normalized() {
		$result = $this->probe( 'measurement-metadata' );
		$flags  = $result['metadata']['opcache_flag'];

		$expected = array(
			'boolean_true'  => true,
			'boolean_false' => false,
			'integer_one'   => true,
			'integer_zero'  => false,
			'float_one'     => true,
			'float_zero'    => false,
			'float_nan'     => false,
			'string_one'    => true,
			'string_on'     => true,
			'string_true'   => true,
			'string_yes'    => true,
			'string_zero'   => false,
			'string_off'    => false,
			'string_empty'  => false,
			'array_value'   => false,
			'null_value'    => false,
		);

		$this->assertSame(
			array_keys( $expected ),
			array_keys( $flags ),
			'Every directive form covered by the probe must be asserted, and every form asserted must be covered.'
		);

		foreach ( $expected as $form => $flag ) {
			$this->assertSame(
				'boolean',
				$flags[ $form ]['type'],
				"The {$form} directive form must normalize to a boolean."
			);
			$this->assertSame(
				$flag ? '1' : '',
				$flags[ $form ]['value'],
				"The {$form} directive form must normalize to " . ( $flag ? 'true' : 'false' ) . '.'
			);
		}
	}

	/**
	 * Tests that only duration metrics are converted from seconds to milliseconds.
	 *
	 * The header carries durations, counts, flags and byte sizes through one loop, and the
	 * conversion is selected by slug. A count that is merely represented as a float must
	 * therefore pass through untouched: scaling one would report a byte total a thousand
	 * times too large, and it would do so without changing the metric's name.
	 */
	public function test_server_timing_value_converts_only_the_duration_slugs() {
		$values = $this->probe( 'measurement-metadata' )['metadata']['header_values'];

		foreach ( array( 'before_template', 'template', 'total', 'bootstrap' ) as $duration ) {
			$this->assertSame(
				12.34,
				$values[ $duration ],
				"The {$duration} slug carries a duration in seconds and must be reported in milliseconds."
			);
		}

		$this->assertSame( 12.35, $values['rounds'], 'A duration must be rounded to two decimal places.' );
		$this->assertSame( 98.7654, $values['float_count'], 'A count represented as a float must not be scaled.' );
		$this->assertSame( 321, $values['count'], 'A count must pass through unchanged.' );
		$this->assertSame( 7445592, $values['bytes'], 'A byte size must pass through unchanged.' );
		$this->assertSame( 1, $values['flag'], 'A flag must pass through unchanged.' );
		$this->assertSame(
			0.01234,
			$values['unknown'],
			'A slug the conversion does not know must pass through unchanged rather than be scaled.'
		);
	}

	/**
	 * Tests that the mu-plugin emits exactly the metrics the specs require of it.
	 *
	 * Every figure the optimization report cites is read out of the Server-Timing header, so a
	 * metric that stops being emitted takes a reported target with it. Deleting one is a silent
	 * change on both sides on its own: the emitting loop iterates whatever it was given, and a
	 * spec that never receives the metric records no samples for it rather than failing, which
	 * leaves the comparison table simply missing a row.
	 *
	 * Requiring the two sides to agree is what makes either side's deletion visible. The
	 * front-end paths carry the two template durations that only a rendered page has; the admin
	 * path carries the same set without them, which is why the request paths are compared
	 * separately rather than as one union.
	 *
	 * @covers ::wp_perf_server_timing_value
	 */
	public function test_the_mu_plugin_emits_every_metric_the_specs_require() {
		$emitted = $this->emitted_metric_slugs();

		$specs = array(
			'front end' => array( 'home.test.js', 'single-post.test.js' ),
			'admin'     => array( 'admin.test.js' ),
		);

		/*
		 * Counted as well as compared: set equality catches a metric removed from one side,
		 * and the counts catch one removed from both, which would otherwise agree with itself.
		 */
		$this->assertCount( 14, $emitted['front end'], 'The front-end path reports fourteen metrics.' );
		$this->assertCount( 12, $emitted['admin'], 'The admin path reports twelve metrics: the same set without the two template durations.' );

		foreach ( $specs as $path => $files ) {
			foreach ( $files as $file ) {
				$this->assertSame(
					$emitted[ $path ],
					$this->required_metric_slugs( $file ),
					"The metrics {$file} requires must be exactly the metrics the mu-plugin emits on the {$path} path."
				);
			}
		}

		// Pinned so that renaming the header prefix cannot pass by agreeing with itself on both sides.
		$this->assertStringContainsString(
			"sprintf( 'wp-%1\$s;dur=%2\$s', \$slug, \$value )",
			$this->mu_plugin_source(),
			'Each metric must be reported as wp-<slug>, which is the name the specs read.'
		);
	}

	/**
	 * Tests that every emitted metric is classified by the reporting layer.
	 *
	 * `formatValue()` in tests/performance/utils.js ends in a duration branch, so a metric it
	 * has not been taught about is still formatted -- as milliseconds. A file count would be
	 * published as '381.00 ms' and a byte total as '7445592.00 ms', both of which read as
	 * measurements rather than as mistakes. Membership of the classification sets is therefore
	 * part of the metric's definition, and is pinned here beside its emission.
	 */
	public function test_every_emitted_metric_is_classified_by_the_reporting_layer() {
		$source = file_get_contents( DIR_TESTROOT . '/../performance/utils.js' );

		$this->assertIsString( $source, 'The performance reporting utilities must be readable.' );

		$classified = array(
			'booleanMetrics'  => $this->js_set_members( $source, 'booleanMetrics' ),
			'countMetrics'    => $this->js_set_members( $source, 'countMetrics' ),
			'validityMetrics' => $this->js_set_members( $source, 'validityMetrics' ),
		);

		/*
		 * Durations are the default and are deliberately absent: they are the one class the
		 * fall-through branch formats correctly.
		 */
		$expected = array(
			'before-template' => 'duration',
			'template'        => 'duration',
			'total'           => 'duration',
			'memory-usage'    => 'bytes',
			'db-queries'      => 'countMetrics',
			'ext-obj-cache'   => 'booleanMetrics',
			'memory-peak'     => 'bytes',
			'files-loaded'    => 'countMetrics',
			'cache-hits'      => 'countMetrics',
			'cache-misses'    => 'countMetrics',
			'bootstrap'       => 'duration',
			'bootstrap-valid' => 'validityMetrics',
			'opcache-enabled' => 'booleanMetrics',
			'opcache-jit'     => 'booleanMetrics',
		);

		$emitted = $this->emitted_metric_slugs();

		$this->assertSame(
			array_keys( $expected ),
			array_values( array_unique( array_merge( $emitted['front end'], $emitted['admin'] ) ) ),
			'Every emitted metric must be classified here, and every classification must belong to an emitted metric.'
		);

		foreach ( $expected as $slug => $class ) {
			$metric = $this->metric_key( $slug );

			if ( 'bytes' === $class ) {
				$this->assertStringContainsString(
					"'{$metric}' === metric",
					$source,
					"The {$slug} metric holds a byte total and must be formatted as one rather than as a duration."
				);

				continue;
			}

			foreach ( $classified as $set => $members ) {
				$this->assertSame(
					$class === $set || ( 'validityMetrics' === $class && 'countMetrics' === $set ),
					in_array( $metric, $members, true ),
					"The {$slug} metric is a {$class} and its membership of {$set} must say so."
				);
			}
		}

		$this->assertSame(
			array( 'wpBootstrapValid' ),
			$classified['validityMetrics'],
			'Only the bootstrap validity flag is a validity flag, and it must stay out of the difference columns.'
		);
	}

	/**
	 * Returns the metric slugs the mu-plugin emits, by request path, in emission order.
	 *
	 * Read from the source rather than from a running request because the two paths are
	 * reached by rendering a front-end template and by loading the admin, neither of which
	 * this suite performs, and because the mu-plugin is deliberately not loaded here at all.
	 *
	 * @return array<string, string[]> Slugs keyed by request path.
	 */
	private function emitted_metric_slugs() {
		$source = $this->mu_plugin_source();
		$admin  = strpos( $source, "'admin_init'" );

		$this->assertIsInt( $admin, 'The mu-plugin must register the admin measurement path.' );

		$template = strpos( $source, "'template_include'" );

		$this->assertIsInt( $template, 'The mu-plugin must register the front-end measurement path.' );
		$this->assertLessThan( $admin, $template, 'The front-end path is expected to be registered first.' );

		$found = preg_match_all(
			"/\\\$server_timing_values\\[\\s*'([a-z-]+)'\\s*\\]\\s*=/",
			$source,
			$matches,
			PREG_OFFSET_CAPTURE
		);

		$this->assertNotSame( 0, $found, 'The mu-plugin must assign at least one metric.' );

		$slugs = array(
			'front end' => array(),
			'admin'     => array(),
		);

		foreach ( $matches[1] as $match ) {
			list( $slug, $offset ) = $match;

			$path = $offset > $admin ? 'admin' : 'front end';

			if ( ! in_array( $slug, $slugs[ $path ], true ) ) {
				$slugs[ $path ][] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * Returns the metric slugs a performance spec requires of every measured navigation.
	 *
	 * @param string $file Spec file name under tests/performance/specs.
	 * @return string[] Slugs, with the wp- prefix the header carries removed.
	 */
	private function required_metric_slugs( $file ) {
		$source = file_get_contents( DIR_TESTROOT . '/../performance/specs/' . $file );

		$this->assertIsString( $source, "The {$file} spec must be readable." );

		$found = preg_match(
			'/const requiredServerTimingMetrics = \[(.*?)\];/s',
			$source,
			$declaration
		);

		$this->assertSame( 1, $found, "The {$file} spec must declare the metrics it requires." );

		preg_match_all( "/'wp-([a-z-]+)'/", $declaration[1], $matches );

		return $matches[1];
	}

	/**
	 * Returns the members of a JavaScript Set declared in the reporting utilities.
	 *
	 * @param string $source Contents of tests/performance/utils.js.
	 * @param string $name   Name of the declared constant.
	 * @return string[] Quoted members of the set, in declaration order.
	 */
	private function js_set_members( $source, $name ) {
		$found = preg_match(
			'/const ' . preg_quote( $name, '/' ) . ' = new Set\( \[(.*?)\] \);/s',
			$source,
			$declaration
		);

		$this->assertSame( 1, $found, "The reporting utilities must declare {$name}." );

		preg_match_all( "/'([A-Za-z]+)'/", $declaration[1], $matches );

		return $matches[1];
	}

	/**
	 * Converts a Server-Timing slug to the metric key the reporting layer uses.
	 *
	 * Mirrors camelCaseDashes() in tests/performance/utils.js, which is what turns a header
	 * name into the key a result artifact is written under.
	 *
	 * @param string $slug Slug as emitted, without the wp- prefix.
	 * @return string Metric key.
	 */
	private function metric_key( $slug ) {
		return lcfirst( str_replace( ' ', '', ucwords( str_replace( '-', ' ', 'wp-' . $slug ) ) ) );
	}

	/**
	 * Returns the contents of the performance mu-plugin.
	 *
	 * @return string Source of tests/performance/wp-content/mu-plugins/server-timing.php.
	 */
	private function mu_plugin_source() {
		$source = file_get_contents( DIR_TESTROOT . '/../performance/wp-content/mu-plugins/server-timing.php' );

		$this->assertIsString( $source, 'The performance mu-plugin must be readable.' );

		return $source;
	}

	/**
	 * Tests that the cache reset the measurement contract depends on is implemented here.
	 *
	 * Every sample this suite reports is labelled uncached, and that label is only true if
	 * the opcode cache, the object cache and the expired transients were discarded before the
	 * measured navigation. clear-cache.php has always done that, but the CI workflow that
	 * provisions the harness copies only this file, so under the shipped workflow
	 * `/?clear_cache` was answered by WordPress as an ordinary front page and nothing was
	 * reset. Answering it here is what makes the regime a property of the harness rather than
	 * of how the harness happened to be provisioned.
	 */
	public function test_the_cache_reset_is_answered_by_the_file_the_workflow_installs() {
		$source = file_get_contents( DIR_TESTROOT . '/../performance/wp-content/mu-plugins/server-timing.php' );

		$this->assertIsString( $source, 'The performance mu-plugin must be readable.' );

		$this->assertMatchesRegularExpression(
			"/\\\$_GET\\[\\s*'clear_cache'\\s*\\]/",
			$source,
			'The mu-plugin must answer the ?clear_cache request itself.'
		);

		foreach ( array( 'opcache_reset', 'apcu_clear_cache', 'wp_cache_flush', 'clearstatcache' ) as $reset ) {
			$this->assertStringContainsString(
				$reset . '(',
				$source,
				"The reset must call {$reset}() so the next measured request is not served from it."
			);
		}

		$this->assertMatchesRegularExpression(
			'/delete_expired_transients\(\s*true\s*\)/',
			$source,
			'The reset must delete expired transients across the whole network.'
		);

		$this->assertMatchesRegularExpression(
			'/status_header\(\s*202\s*\)/',
			$source,
			'The reset must answer 202, which WordPress never sends for that URL, so a spec can require it.'
		);

		$this->assertMatchesRegularExpression(
			'/\bdie\b/',
			$source,
			'The reset must end the request rather than go on to render a page.'
		);

		/*
		 * The query argument selects the endpoint and must authorize nothing. Reading it
		 * for anything other than an isset() test would put the decision back on data an
		 * attacker supplies in a URL.
		 */
		$this->assertSame(
			1,
			preg_match_all( "/\\\$_GET\\[\\s*'clear_cache'\\s*\\]/", $source ),
			'The clear_cache query argument must be read exactly once, by the isset() test that selects the endpoint.'
		);

		$this->assertMatchesRegularExpression(
			"/isset\(\s*\\\$_GET\[\s*'clear_cache'\s*\]\s*\)/",
			$source,
			'The clear_cache query argument must only ever be tested with isset(), never compared against a secret.'
		);

		$this->assertStringContainsString(
			'\'POST\' !== strtoupper( $method )',
			$source,
			'The reset must accept POST only, so it cannot be reached by a navigation, a prefetch or an embedded resource.'
		);

		$this->assertStringContainsString(
			'hash_equals( $token, $presented )',
			$source,
			'The presented secret must be compared with hash_equals(), so the comparison is not a timing oracle.'
		);

		$this->assertStringContainsString(
			"\$_SERVER['HTTP_X_WP_PERF_CACHE_RESET_TOKEN']",
			$source,
			'The secret must be read from the X-WP-Perf-Cache-Reset-Token request header, which a cross-origin form cannot set.'
		);

		foreach ( array( 404, 405, 403 ) as $refusal ) {
			$this->assertMatchesRegularExpression(
				'/\breturn ' . $refusal . ';/',
				$source,
				"The control plane must fail closed with {$refusal} rather than fall through to the reset."
			);
		}
	}

	/**
	 * Tests that the cache reset control plane does not exist until a secret is provisioned.
	 *
	 * The reset discards the opcode cache, the object cache and the expired transients for
	 * the whole installation, so an installation that merely has this mu-plugin present must
	 * have no reset endpoint at all: 404, disclosing nothing, not even which cache layers
	 * the host runs. A provisioned but unusable secret has to fail the same way rather than
	 * bring the endpoint up protected by something guessable, which is what the weak-token
	 * fixture measures.
	 */
	public function test_the_cache_reset_control_plane_is_disabled_without_a_provisioned_secret() {
		foreach ( array( 'cache-reset-disabled', 'cache-reset-weak-token' ) as $fixture ) {
			$result = $this->probe( $fixture );

			$this->assertFalse(
				$result['cache_reset']['token_resolved'],
				"The {$fixture} fixture must resolve no secret, so the endpoint does not exist."
			);

			$this->assertSame(
				0,
				$result['cache_reset']['token_length'],
				"The {$fixture} fixture must report an empty secret rather than a short one."
			);

			$this->assertNotEmpty(
				$result['cache_reset']['statuses'],
				"The {$fixture} fixture must measure the request shapes rather than none of them."
			);

			foreach ( $result['cache_reset']['statuses'] as $shape => $status ) {
				$this->assertSame(
					404,
					$status,
					"With no secret provisioned, {$shape} must be answered 404: the endpoint does not exist."
				);
			}
		}
	}

	/**
	 * Tests that a provisioned reset is reachable only by a POST presenting the secret.
	 *
	 * Every refusal is measured, not inferred: the decision is a pure function of the method
	 * and the presented secret, so each request shape is put to it directly. A GET carrying
	 * the correct secret is included deliberately, because that is the shape a secret in the
	 * URL would take, and it must still be refused.
	 */
	public function test_the_cache_reset_requires_an_authenticated_post() {
		$result = $this->probe( 'cache-reset-enabled' );

		$this->assertTrue(
			$result['cache_reset']['token_resolved'],
			'The enabled fixture must resolve the secret it provisions.'
		);

		$this->assertSame(
			64,
			$result['cache_reset']['token_length'],
			'The enabled fixture must provision a secret of the shape the harness writes.'
		);

		$expected = array(
			// Wrong method: refused whatever was presented, including the correct secret.
			'get_without_token'                  => 405,
			'get_with_correct_token'             => 405,
			'head_with_correct_token'            => 405,
			'put_with_correct_token'             => 405,
			'delete_with_correct_token'          => 405,
			'options_with_correct_token'         => 405,
			'empty_method_with_correct_token'    => 405,
			'nonstring_method'                   => 405,
			// Right method, unacceptable secret.
			'post_without_token'                 => 403,
			'post_with_wrong_token'              => 403,
			'post_with_truncated_token'          => 403,
			'post_with_extended_token'           => 403,
			'post_with_case_changed_token'       => 403,
			'post_with_padded_token'             => 403,
			'post_with_nonstring_token'          => 403,
			'post_with_null_token'               => 403,
			// The only shape that resets anything.
			'post_with_correct_token'            => 202,
			'lowercased_post_with_correct_token' => 202,
		);

		$this->assertSame(
			$expected,
			$result['cache_reset']['statuses'],
			'A provisioned reset must answer exactly these statuses, in this order, for these request shapes.'
		);

		$this->assertSame(
			405,
			$result['cache_reset']['statuses']['get_with_correct_token'],
			'A GET must be refused even when it presents the correct secret, so a secret placed in a URL cannot authorize a reset.'
		);

		$this->assertSame(
			403,
			$result['cache_reset']['statuses']['post_without_token'],
			'An unauthenticated POST must be refused.'
		);

		$this->assertSame(
			403,
			$result['cache_reset']['statuses']['post_with_wrong_token'],
			'A POST presenting the wrong secret must be refused.'
		);

		$this->assertSame(
			202,
			$result['cache_reset']['statuses']['post_with_correct_token'],
			'A POST presenting the provisioned secret is the one shape that must be accepted.'
		);

		$this->assertSame(
			array(),
			$result['diagnostics'],
			'Deciding any of these request shapes must raise no diagnostic, whatever was presented.'
		);

		$this->assertSame(
			0,
			$result['output_length'],
			'Deciding a request shape must print nothing.'
		);
	}

	/**
	 * Tests that the reset is registered on the same boundary as the pre-existing helper.
	 *
	 * Must-use plugins load in filename order, so clear-cache.php registers first and would
	 * exit first. That ordering is unchanged, and it is only meaningful while the two share a
	 * hook and a priority, so the pairing is pinned here.
	 *
	 * What is no longer shared is the authorization. clear-cache.php runs the same reset for
	 * any request carrying the query argument, so an installation holding both files would
	 * let it answer first and reinstate the unauthenticated reset that server-timing.php
	 * refuses. Only server-timing.php may be provisioned, which is what both performance
	 * workflows already do: each copies that one file into the installed tree. The divergence
	 * is asserted rather than described, so a future change that made the two behave alike
	 * again has to be a deliberate one.
	 */
	public function test_the_cache_reset_matches_the_pre_existing_helper() {
		$helper = file_get_contents( DIR_TESTROOT . '/../performance/wp-content/mu-plugins/clear-cache.php' );

		$this->assertIsString( $helper, 'The pre-existing cache reset helper must be readable.' );

		$this->assertMatchesRegularExpression(
			"/'plugins_loaded'/",
			$helper,
			'The pre-existing helper answers plugins_loaded, which is the boundary the mu-plugin must share.'
		);

		$registration = self::REGISTRATIONS[0];

		$this->assertSame( 'plugins_loaded', $registration['hook'] );
		$this->assertSame( 1, $registration['priority'] );

		$this->assertStringNotContainsString(
			'hash_equals',
			$helper,
			'The pre-existing helper authorizes nothing, which is why it must not be provisioned beside server-timing.php.'
		);

		$this->assertStringContainsString(
			'hash_equals',
			$this->mu_plugin_source(),
			'server-timing.php is the file that authorizes the reset, and it is the only one the workflows install.'
		);

		foreach (
			array(
				'.github/workflows/reusable-performance.yml',
				'.github/workflows/reusable-performance-test-v2.yml',
			) as $workflow
		) {
			$provisioning = file_get_contents( DIR_TESTROOT . '/../../' . $workflow );

			$this->assertIsString( $provisioning, "The {$workflow} workflow must be readable." );

			$this->assertStringContainsString(
				'cp ./tests/performance/wp-content/mu-plugins/server-timing.php',
				$provisioning,
				"The {$workflow} workflow must provision server-timing.php."
			);

			$this->assertStringNotContainsString(
				'clear-cache.php',
				$provisioning,
				"The {$workflow} workflow must not provision clear-cache.php, which would reset the caches for any request."
			);
		}
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
			function_exists( 'wp_perf_opcache_directive' ),
			'The performance mu-plugin must not be loaded by the test suite: its output buffering makes other tests unreliable.'
		);

		$this->assertFalse(
			function_exists( 'wp_perf_opcache_flag' ),
			'The performance mu-plugin must not be loaded by the test suite: its output buffering makes other tests unreliable.'
		);

		$this->assertFalse(
			function_exists( 'wp_perf_opcache_metadata' ),
			'The performance mu-plugin must not be loaded by the test suite: its output buffering makes other tests unreliable.'
		);

		$this->assertFalse(
			function_exists( 'wp_perf_reset_caches' ),
			'The performance mu-plugin must not be loaded by the test suite: its output buffering makes other tests unreliable, and its cache reset would answer any authorized request carrying a clear_cache query argument.'
		);

		$this->assertFalse(
			function_exists( 'wp_perf_cache_reset_token' ),
			'The performance mu-plugin must not be loaded by the test suite: its output buffering makes other tests unreliable, and its cache reset control plane belongs to the performance harness alone.'
		);

		$this->assertFalse(
			function_exists( 'wp_perf_cache_reset_status' ),
			'The performance mu-plugin must not be loaded by the test suite: its output buffering makes other tests unreliable, and its cache reset control plane belongs to the performance harness alone.'
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

		$measured = array(
			'bootstrap-duration',
			'measurement-metadata',
			'cache-reset-disabled',
			'cache-reset-weak-token',
			'cache-reset-enabled',
		);

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
