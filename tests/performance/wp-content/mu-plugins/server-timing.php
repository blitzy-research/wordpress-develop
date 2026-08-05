<?php

/**
 * Stores or retrieves the duration of the WordPress bootstrap sequence, in seconds.
 *
 * The metric has exactly one boundary: the interval from $timestart to 'wp_loaded'.
 * That boundary is the same for the front-end and the admin scenario, so the two are
 * comparable, and there is deliberately no second boundary to fall back to. A request
 * that never reaches 'wp_loaded' therefore has no bootstrap duration at all rather
 * than one measured to a different end point, and this function reports that as null.
 *
 * Distinguishing "never recorded" from a recorded 0.0 is what keeps the reported
 * figure aggregatable. A placeholder duration is a number like any other once it
 * reaches the results table, so it would be pulled into the median of the samples
 * that were genuinely measured. The callers below turn the null into an explicit
 * 'bootstrap-valid' flag so an unmeasured sample can be recognized and dropped
 * instead of silently averaged in.
 *
 * @ignore
 * @since 7.0.0
 * @access private
 *
 * @param float|null $duration Optional. Duration in seconds to record. Default null,
 *                             which returns the previously recorded duration.
 * @return float|null Bootstrap duration in seconds, or null if the 'wp_loaded'
 *                    boundary was never reached.
 */
function wp_perf_bootstrap_duration( $duration = null ) {
	static $bootstrap = null;

	if ( null !== $duration ) {
		$bootstrap = (float) $duration;
	}

	return $bootstrap;
}

/**
 * Returns the object cache hit and miss counters as validated integers.
 *
 * The object cache global can be replaced wholesale by an 'object-cache.php' drop-in,
 * which is free to omit these counters, declare them non-public, expose them through
 * magic accessors, or keep something other than a number in them. Reading the
 * properties directly would therefore be able to run drop-in code, throw, or emit a
 * conversion notice from inside a 'shutdown' callback that has already pulled the
 * response body out of the output buffer, which would corrupt the measured response
 * and leak internal error detail whenever display errors are on.
 *
 * get_object_vars() is called from outside the class, so the snapshot it returns holds
 * only genuinely public properties and no magic accessor is ever consulted. Anything
 * that is not a numeric scalar with an integer representation is reported as 0, which is
 * the same value an entirely absent counter reports, so the metric degrades without ever
 * being omitted.
 *
 * @ignore
 * @since 7.0.0
 * @access private
 *
 * @return int[] {
 *     Object cache counters, both 0 when the counter is unavailable or unusable.
 *
 *     @type int $hits   Number of object cache hits.
 *     @type int $misses Number of object cache misses.
 * }
 */
function wp_perf_object_cache_counters() {
	$counters = array(
		'hits'   => 0,
		'misses' => 0,
	);

	if ( ! isset( $GLOBALS['wp_object_cache'] ) || ! is_object( $GLOBALS['wp_object_cache'] ) ) {
		return $counters;
	}

	$public_properties = get_object_vars( $GLOBALS['wp_object_cache'] );

	$counter_properties = array(
		'hits'   => 'cache_hits',
		'misses' => 'cache_misses',
	);

	foreach ( $counter_properties as $counter => $property ) {
		if ( ! isset( $public_properties[ $property ] ) ) {
			continue;
		}

		$counter_value = $public_properties[ $property ];

		if ( is_int( $counter_value ) ) {
			$counters[ $counter ] = $counter_value;
			continue;
		}

		if ( ! is_numeric( $counter_value ) ) {
			continue;
		}

		/*
		 * A numeric string or a float is still usable, but only when it has an integer
		 * representation: casting a non-finite or out-of-range float emits
		 * "The float ... is not representable as an int" as of PHP 8.5, which is exactly
		 * the kind of notice this function exists to keep out of the measured response.
		 */
		$counter_number = (float) $counter_value;

		if ( is_finite( $counter_number ) && $counter_number >= (float) PHP_INT_MIN && $counter_number < (float) PHP_INT_MAX ) {
			$counters[ $counter ] = (int) $counter_number;
		}
	}

	return $counters;
}

/**
 * Reduces an OPcache status snapshot to stable numeric measurement metadata.
 *
 * opcache_get_status() can be unavailable, disabled, restricted, or return false.
 * Every field therefore has a numeric zero fallback so no measured iteration can
 * silently omit a regime field and leave an unusable sample series.
 *
 * @ignore
 * @since 7.0.0
 * @access private
 *
 * @param array|false $status OPcache status, or false when unavailable.
 * @return array {
 *     Numeric OPcache regime metadata.
 *
 *     @type int   $opcache-enabled        Whether OPcache is enabled.
 *     @type int   $opcache-jit            Whether OPcache JIT is active.
 *     @type int   $opcache-cached-scripts Number of scripts cached by OPcache.
 *     @type float $opcache-hit-rate       OPcache hit rate as a percentage.
 * }
 */
function wp_perf_opcache_metadata( $status ) {
	$metadata = array(
		'opcache-enabled'        => 0,
		'opcache-jit'            => 0,
		'opcache-cached-scripts' => 0,
		'opcache-hit-rate'       => 0.0,
	);

	if ( ! is_array( $status ) || empty( $status['opcache_enabled'] ) ) {
		return $metadata;
	}

	$metadata['opcache-enabled'] = 1;

	if ( isset( $status['jit'] ) && is_array( $status['jit'] ) && ! empty( $status['jit']['on'] ) ) {
		$metadata['opcache-jit'] = 1;
	}

	if ( ! isset( $status['opcache_statistics'] ) || ! is_array( $status['opcache_statistics'] ) ) {
		return $metadata;
	}

	$statistics = $status['opcache_statistics'];

	if ( isset( $statistics['num_cached_scripts'] ) && is_numeric( $statistics['num_cached_scripts'] ) ) {
		$cached_scripts = (float) $statistics['num_cached_scripts'];

		if ( is_finite( $cached_scripts ) && 0.0 <= $cached_scripts && $cached_scripts < (float) PHP_INT_MAX ) {
			$metadata['opcache-cached-scripts'] = (int) $cached_scripts;
		}
	}

	if ( isset( $statistics['opcache_hit_rate'] ) && is_numeric( $statistics['opcache_hit_rate'] ) ) {
		$hit_rate = (float) $statistics['opcache_hit_rate'];

		if ( is_finite( $hit_rate ) && 0.0 <= $hit_rate && 100.0 >= $hit_rate ) {
			$metadata['opcache-hit-rate'] = round( $hit_rate, 4 );
		}
	}

	return $metadata;
}

/**
 * Returns a stable identifier for the current operating-system process generation.
 *
 * Linux exposes the process start tick in /proc. Pairing it with the process ID
 * prevents a stale counter from surviving the unlikely reuse of a PID after a PHP
 * worker restart. Other platforms return 0, where the process ID remains the best
 * generation identifier available to this test instrumentation.
 *
 * @ignore
 * @since 7.0.0
 * @access private
 *
 * @return int Process start tick, or 0 when unavailable.
 */
function wp_perf_process_generation() {
	$process_id = getmypid();

	if ( false === $process_id ) {
		return 0;
	}

	$stat_file = '/proc/' . (int) $process_id . '/stat';

	if ( ! is_readable( $stat_file ) ) {
		return 0;
	}

	// The process can exit between the readability check and the read.
	$stat = @file_get_contents( $stat_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( ! is_string( $stat ) ) {
		return 0;
	}

	$command_end = strrpos( $stat, ')' );

	if ( false === $command_end ) {
		return 0;
	}

	$fields = preg_split( '/\s+/', trim( substr( $stat, $command_end + 1 ) ) );

	/*
	 * The substring starts at field 3 (state), so field 22 (process start time)
	 * is offset 19. It is expressed as an unsigned integer number of clock ticks.
	 */
	if ( ! isset( $fields[19] ) || ! ctype_digit( $fields[19] ) ) {
		return 0;
	}

	return (int) $fields[19];
}

/**
 * Increments the request counter for one PHP process generation.
 *
 * PHP request state is reset between FastCGI requests, so an in-memory static cannot
 * identify how warm a reused worker is. A tiny locked counter in the system temporary
 * directory supplies that missing evidence without touching WordPress caches or the
 * database metrics being measured. The generation value resets a reused PID safely.
 *
 * @ignore
 * @since 7.0.0
 * @access private
 *
 * @param int         $process_id         Process ID.
 * @param int         $process_generation Process start tick.
 * @param string|null $counter_directory  Optional. Counter directory. Default null
 *                                        uses WP_PERFORMANCE_PROCESS_COUNTER_DIR when
 *                                        defined, or the system temporary directory.
 * @return int Request number in this process generation, or 0 when unavailable.
 */
function wp_perf_process_request_count( $process_id, $process_generation, $counter_directory = null ) {
	$process_id         = (int) $process_id;
	$process_generation = (int) $process_generation;

	if ( 0 >= $process_id ) {
		return 0;
	}

	if ( null === $counter_directory ) {
		$counter_directory = defined( 'WP_PERFORMANCE_PROCESS_COUNTER_DIR' )
			? WP_PERFORMANCE_PROCESS_COUNTER_DIR
			: sys_get_temp_dir();
	}

	if ( ! is_string( $counter_directory ) || ! is_dir( $counter_directory ) || ! is_writable( $counter_directory ) ) {
		return 0;
	}

	$counter_file = rtrim( $counter_directory, '/\\' ) . '/wp-performance-process-' . $process_id . '.counter';

	// The directory can become unavailable between the checks above and the open.
	$handle = @fopen( $counter_file, 'c+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( false === $handle ) {
		return 0;
	}

	if ( ! flock( $handle, LOCK_EX ) ) {
		fclose( $handle );
		return 0;
	}

	$stored  = stream_get_contents( $handle );
	$counter = 0;

	if ( is_string( $stored ) && preg_match( '/^(-?\d+):(\d+)$/D', trim( $stored ), $matches ) ) {
		if ( (string) $process_generation === $matches[1] ) {
			$counter = (int) $matches[2];
		}
	}

	if ( PHP_INT_MAX > $counter ) {
		++$counter;
	}

	$serialized = $process_generation . ':' . $counter . "\n";

	rewind( $handle );
	$written = ftruncate( $handle, 0 ) && strlen( $serialized ) === fwrite( $handle, $serialized ) && fflush( $handle );

	flock( $handle, LOCK_UN );
	fclose( $handle );

	return $written ? $counter : 0;
}

/**
 * Returns immutable interpreter and process metadata for the current request.
 *
 * The result is memoized so multiple consumers in one request observe one request
 * number and one OPcache snapshot. A new PHP request recomputes it, allowing the
 * process counter and OPcache warmness indicators to advance honestly.
 *
 * @ignore
 * @since 7.0.0
 * @access private
 *
 * @return array Numeric runtime metadata keyed by Server-Timing slug.
 */
function wp_perf_runtime_metadata() {
	static $metadata = null;

	if ( null !== $metadata ) {
		return $metadata;
	}

	$opcache_status = false;

	if ( function_exists( 'opcache_get_status' ) ) {
		// The function warns when OPcache status access is restricted.
		$opcache_status = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	$opcache  = wp_perf_opcache_metadata( $opcache_status );
	$process  = getmypid();
	$process  = false === $process ? 0 : (int) $process;
	$metadata = array(
		'opcache-enabled'        => $opcache['opcache-enabled'],
		'opcache-jit'            => $opcache['opcache-jit'],
		'php-version-id'         => (int) PHP_VERSION_ID,
		'process-id'             => $process,
		'process-requests'       => wp_perf_process_request_count( $process, wp_perf_process_generation() ),
		'opcache-cached-scripts' => $opcache['opcache-cached-scripts'],
		'opcache-hit-rate'       => $opcache['opcache-hit-rate'],
	);

	return $metadata;
}

/**
 * Converts a Server-Timing value to the unit emitted in the response header.
 *
 * Only durations are stored internally as seconds. Counts, flags, identifiers, byte
 * sizes, and percentages must pass through unchanged even when represented as floats.
 *
 * @ignore
 * @since 7.0.0
 * @access private
 *
 * @param string    $slug  Server-Timing metric slug.
 * @param int|float $value Numeric value.
 * @return int|float Value in the header's unit.
 */
function wp_perf_server_timing_value( $slug, $value ) {
	static $duration_metrics = array(
		'before-template' => true,
		'template'        => true,
		'total'           => true,
		'bootstrap'       => true,
	);

	if ( isset( $duration_metrics[ $slug ] ) ) {
		return round( (float) $value * 1000.0, 2 );
	}

	return $value;
}

add_action(
	'wp_loaded',
	static function () {
		global $timestart;

		wp_perf_bootstrap_duration( microtime( true ) - $timestart );
	},
	PHP_INT_MAX
);

add_filter(
	'template_include',
	static function ( $template ) {

		global $timestart, $wpdb;

		$server_timing_values = array();
		$template_start       = microtime( true );

		$server_timing_values['before-template'] = $template_start - $timestart;

		ob_start();

		add_action(
			'shutdown',
			static function () use ( $server_timing_values, $template_start, $wpdb ) {
				/*
				 * Sampled first, before this callback allocates anything of its own.
				 *
				 * ob_get_clean() below copies the entire buffered response into a PHP
				 * string, and wp_perf_object_cache_counters() copies the object cache's
				 * public properties, so either is able to push the process peak above
				 * whatever the measured request actually reached. Reading the peak here
				 * keeps the instrumentation out of the number it reports. Current usage
				 * is deliberately left where upstream reads it, since moving it would
				 * change what the pre-existing 'memory-usage' metric means.
				 */
				$memory_peak = (int) memory_get_peak_usage( false );

				$output = ob_get_clean();

				$server_timing_values['template'] = microtime( true ) - $template_start;

				$server_timing_values['total'] = $server_timing_values['before-template'] + $server_timing_values['template'];

				// Both cache metrics come from one validated snapshot, so a replacement object cache is never touched twice.
				$cache_counters = wp_perf_object_cache_counters();

				$runtime = wp_perf_runtime_metadata();

				// Null exactly when the single 'wp_loaded' boundary was never reached.
				$bootstrap = wp_perf_bootstrap_duration();

				/*
				 * While values passed via Server-Timing are intended to be durations,
				 * any numeric value can actually be passed.
				 * This is a nice little trick as it allows to easily get this information in JS.
				 */
				$server_timing_values['memory-usage']           = memory_get_usage();
				$server_timing_values['db-queries']             = $wpdb->num_queries;
				$server_timing_values['ext-obj-cache']          = wp_using_ext_object_cache() ? 1 : 0;
				$server_timing_values['memory-peak']            = $memory_peak;
				$server_timing_values['files-loaded']           = (int) count( get_included_files() );
				$server_timing_values['cache-hits']             = $cache_counters['hits'];
				$server_timing_values['cache-misses']           = $cache_counters['misses'];
				$server_timing_values['bootstrap']              = null === $bootstrap ? 0.0 : $bootstrap;
				$server_timing_values['bootstrap-valid']        = null === $bootstrap ? 0 : 1;
				$server_timing_values['opcache-enabled']        = $runtime['opcache-enabled'];
				$server_timing_values['opcache-jit']            = $runtime['opcache-jit'];
				$server_timing_values['php-version-id']         = $runtime['php-version-id'];
				$server_timing_values['process-id']             = $runtime['process-id'];
				$server_timing_values['process-requests']       = $runtime['process-requests'];
				$server_timing_values['opcache-cached-scripts'] = $runtime['opcache-cached-scripts'];
				$server_timing_values['opcache-hit-rate']       = $runtime['opcache-hit-rate'];

				$header_values = array();
				foreach ( $server_timing_values as $slug => $value ) {
					$value = wp_perf_server_timing_value( $slug, $value );

					$header_values[] = sprintf( 'wp-%1$s;dur=%2$s', $slug, $value );
				}
				header( 'Server-Timing: ' . implode( ', ', $header_values ) );

				echo $output;
			},
			PHP_INT_MIN
		);

		return $template;
	},
	PHP_INT_MAX
);

add_action(
	'admin_init',
	static function () {
		global $timestart, $wpdb;

		ob_start();

		add_action(
			'shutdown',
			static function () use ( $wpdb, $timestart ) {
				/*
				 * Sampled first, before this callback allocates anything of its own, for
				 * the same reason as in the front-end path above: ob_get_clean() and the
				 * cache-counter snapshot both allocate, so reading the peak afterwards
				 * would let the instrumentation set the figure it reports.
				 */
				$memory_peak = (int) memory_get_peak_usage( false );

				$output = ob_get_clean();

				$server_timing_values = array();

				$server_timing_values['total'] = microtime( true ) - $timestart;

				// Both cache metrics come from one validated snapshot, so a replacement object cache is never touched twice.
				$cache_counters = wp_perf_object_cache_counters();

				$runtime = wp_perf_runtime_metadata();

				// Null exactly when the single 'wp_loaded' boundary was never reached.
				$bootstrap = wp_perf_bootstrap_duration();

				/*
				 * While values passed via Server-Timing are intended to be durations,
				 * any numeric value can actually be passed.
				 * This is a nice little trick as it allows to easily get this information in JS.
				 */
				$server_timing_values['memory-usage']           = memory_get_usage();
				$server_timing_values['db-queries']             = $wpdb->num_queries;
				$server_timing_values['ext-obj-cache']          = wp_using_ext_object_cache() ? 1 : 0;
				$server_timing_values['memory-peak']            = $memory_peak;
				$server_timing_values['files-loaded']           = (int) count( get_included_files() );
				$server_timing_values['cache-hits']             = $cache_counters['hits'];
				$server_timing_values['cache-misses']           = $cache_counters['misses'];
				$server_timing_values['bootstrap']              = null === $bootstrap ? 0.0 : $bootstrap;
				$server_timing_values['bootstrap-valid']        = null === $bootstrap ? 0 : 1;
				$server_timing_values['opcache-enabled']        = $runtime['opcache-enabled'];
				$server_timing_values['opcache-jit']            = $runtime['opcache-jit'];
				$server_timing_values['php-version-id']         = $runtime['php-version-id'];
				$server_timing_values['process-id']             = $runtime['process-id'];
				$server_timing_values['process-requests']       = $runtime['process-requests'];
				$server_timing_values['opcache-cached-scripts'] = $runtime['opcache-cached-scripts'];
				$server_timing_values['opcache-hit-rate']       = $runtime['opcache-hit-rate'];

				$header_values = array();
				foreach ( $server_timing_values as $slug => $value ) {
					$value = wp_perf_server_timing_value( $slug, $value );

					$header_values[] = sprintf( 'wp-%1$s;dur=%2$s', $slug, $value );
				}
				header( 'Server-Timing: ' . implode( ', ', $header_values ) );

				echo $output;
			},
			PHP_INT_MIN
		);
	},
	PHP_INT_MAX
);
