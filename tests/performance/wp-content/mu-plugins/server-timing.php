<?php

/**
 * Records and returns the duration of the WordPress bootstrap sequence, in seconds.
 *
 * The duration is captured once per request by the 'wp_loaded' callback registered
 * below, and is read back by both of the 'shutdown' callbacks in this file.
 *
 * Routing the value through a single accessor is what keeps the metric comparable
 * between scenarios: 'wp_loaded' is the final hook of the bootstrap sequence, so it is
 * reached at the same point of the request lifecycle on the front end and in the admin,
 * whereas 'before-template' has no admin equivalent. It also avoids reaching for
 * $timestart inside the front-end 'shutdown' callback, which does not import it.
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
				$server_timing_values['cache-hits']    = isset( $GLOBALS['wp_object_cache']->cache_hits ) ? (int) $GLOBALS['wp_object_cache']->cache_hits : 0;
				$server_timing_values['cache-misses']  = isset( $GLOBALS['wp_object_cache']->cache_misses ) ? (int) $GLOBALS['wp_object_cache']->cache_misses : 0;
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
				$server_timing_values['cache-hits']    = isset( $GLOBALS['wp_object_cache']->cache_hits ) ? (int) $GLOBALS['wp_object_cache']->cache_hits : 0;
				$server_timing_values['cache-misses']  = isset( $GLOBALS['wp_object_cache']->cache_misses ) ? (int) $GLOBALS['wp_object_cache']->cache_misses : 0;
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
