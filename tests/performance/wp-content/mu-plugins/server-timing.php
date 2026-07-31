<?php

/**
 * Stores or retrieves the duration of the WordPress bootstrap sequence, in seconds.
 *
 * Capturing the duration at 'wp_loaded' gives the front-end and admin scenarios the
 * same bootstrap boundary, so the metric is comparable between them.
 *
 * @ignore
 * @since 7.0.0
 * @access private
 *
 * @param float|null $duration Optional. Duration in seconds to record. Default null,
 *                             which returns the previously recorded duration.
 * @return float Bootstrap duration in seconds, or 0.0 if it was never recorded.
 */
function wp_perf_bootstrap_duration( $duration = null ) {
	static $bootstrap = 0.0;

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
				$output = ob_get_clean();

				$server_timing_values['template'] = microtime( true ) - $template_start;

				$server_timing_values['total'] = $server_timing_values['before-template'] + $server_timing_values['template'];

				// Both cache metrics come from one validated snapshot, so a replacement object cache is never touched twice.
				$cache_counters = wp_perf_object_cache_counters();

				/*
				 * While values passed via Server-Timing are intended to be durations,
				 * any numeric value can actually be passed.
				 * This is a nice little trick as it allows to easily get this information in JS.
				 *
				 * Counts must therefore stay integers: the loop below scales any float by 1000.
				 */
				$server_timing_values['memory-usage']  = memory_get_usage();
				$server_timing_values['db-queries']    = $wpdb->num_queries;
				$server_timing_values['ext-obj-cache'] = wp_using_ext_object_cache() ? 1 : 0;
				$server_timing_values['memory-peak']   = (int) memory_get_peak_usage( false );
				$server_timing_values['files-loaded']  = (int) count( get_included_files() );
				$server_timing_values['cache-hits']    = $cache_counters['hits'];
				$server_timing_values['cache-misses']  = $cache_counters['misses'];
				$server_timing_values['bootstrap']     = wp_perf_bootstrap_duration();

				$header_values = array();
				foreach ( $server_timing_values as $slug => $value ) {
					if ( is_float( $value ) ) {
						$value = round( $value * 1000.0, 2 );
					}
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
				$output = ob_get_clean();

				$server_timing_values = array();

				$server_timing_values['total'] = microtime( true ) - $timestart;

				// Both cache metrics come from one validated snapshot, so a replacement object cache is never touched twice.
				$cache_counters = wp_perf_object_cache_counters();

				/*
				 * While values passed via Server-Timing are intended to be durations,
				 * any numeric value can actually be passed.
				 * This is a nice little trick as it allows to easily get this information in JS.
				 *
				 * Counts must therefore stay integers: the loop below scales any float by 1000.
				 */
				$server_timing_values['memory-usage']  = memory_get_usage();
				$server_timing_values['db-queries']    = $wpdb->num_queries;
				$server_timing_values['ext-obj-cache'] = wp_using_ext_object_cache() ? 1 : 0;
				$server_timing_values['memory-peak']   = (int) memory_get_peak_usage( false );
				$server_timing_values['files-loaded']  = (int) count( get_included_files() );
				$server_timing_values['cache-hits']    = $cache_counters['hits'];
				$server_timing_values['cache-misses']  = $cache_counters['misses'];
				$server_timing_values['bootstrap']     = wp_perf_bootstrap_duration();

				$header_values = array();
				foreach ( $server_timing_values as $slug => $value ) {
					if ( is_float( $value ) ) {
						$value = round( $value * 1000.0, 2 );
					}
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
