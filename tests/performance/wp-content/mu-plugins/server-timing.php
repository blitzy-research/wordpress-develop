<?php

/**
 * Resolves the secret that authorizes a cache reset, or reports that there is none.
 *
 * Provisioning a secret is what enables the reset; the empty string returned when none is
 * usable is what makes the control plane below answer as though the endpoint did not
 * exist. The performance harness provisions a token before its first measured iteration
 * (`tests/performance/utils.js`) and its global teardown is meant to delete it again
 * (`tests/performance/config/global-teardown.js`).
 *
 * Three sources are consulted, in this order:
 *
 * 1. The `WP_PERF_CACHE_RESET_TOKEN` constant, for a `wp-config.php` deployment.
 * 2. The `WP_PERF_CACHE_RESET_TOKEN` environment variable, for a container deployment.
 * 3. A token file, which is what the harness uses. Its path comes from the
 *    `WP_PERF_CACHE_RESET_TOKEN_FILE` constant or environment variable, and otherwise
 *    defaults to `.cache/performance-cache-reset-token` beside the installation directory.
 *
 * A candidate is accepted only as 32 to 128 alphanumeric characters, so a truncated file,
 * an empty variable or a short hand-written value disables the reset rather than
 * protecting it with something guessable. Only language functions are used, so the
 * decision can be reached in a process where WordPress has not been loaded.
 *
 * @ignore
 * @since 7.0.0
 * @access private
 *
 * @return string Token that authorizes a reset, or '' when resets are disabled.
 */
function wp_perf_cache_reset_token() {
	static $token = null;

	if ( null !== $token ) {
		return $token;
	}

	$token = '';

	$candidates = array();

	if ( defined( 'WP_PERF_CACHE_RESET_TOKEN' ) ) {
		$candidates[] = WP_PERF_CACHE_RESET_TOKEN;
	}

	$candidates[] = getenv( 'WP_PERF_CACHE_RESET_TOKEN' );

	$file = null;

	if ( defined( 'WP_PERF_CACHE_RESET_TOKEN_FILE' ) ) {
		$file = WP_PERF_CACHE_RESET_TOKEN_FILE;
	} else {
		$configured = getenv( 'WP_PERF_CACHE_RESET_TOKEN_FILE' );

		if ( is_string( $configured ) && '' !== $configured ) {
			$file = $configured;
		} elseif ( defined( 'ABSPATH' ) ) {
			/*
			 * Beside the installation directory rather than inside it. In the performance
			 * harness layout ABSPATH is the served document root, so a token kept under it
			 * would be fetchable over HTTP. A deployment that serves the parent directory
			 * as well has to configure a path of its own outside the served root.
			 */
			$file = dirname( rtrim( ABSPATH, '/\\' ) ) . '/.cache/performance-cache-reset-token';
		}
	}

	if ( is_string( $file ) && '' !== $file && is_readable( $file ) ) {
		$contents = file_get_contents( $file );

		if ( is_string( $contents ) ) {
			$candidates[] = trim( $contents );
		}
	}

	foreach ( $candidates as $candidate ) {
		if ( is_string( $candidate ) && preg_match( '/^[A-Za-z0-9]{32,128}$/', $candidate ) ) {
			$token = $candidate;
			break;
		}
	}

	return $token;
}

/**
 * Decides what the cache reset control plane answers one reset request with.
 *
 * Separated from the request so the status can be selected from the request method, the
 * presented secret and the provisioned token state alone, which is what lets every branch
 * be reached directly rather than inferred from a live response. It has no side effects
 * and resets nothing itself. Each step answers with the status that describes only that
 * step, and the ladder fails closed:
 *
 * - 404 when no token has been provisioned, disclosing nothing about the installation.
 * - 405 for any method other than POST, so a reset is not reachable by navigation,
 *   prefetch, image load or link preview.
 * - 403 when the presented secret is absent or does not match, compared with
 *   `hash_equals()` so the comparison is not a timing oracle.
 * - 202 only when a POST presented the provisioned token.
 *
 * The caller below reads the token from a request header rather than from the URL, which
 * keeps it out of the address, the referrer and the browser history, and out of the request
 * logs the harness is configured with.
 *
 * @ignore
 * @since 7.0.0
 * @access private
 *
 * @param string $method    Request method, as reported by the server.
 * @param string $presented Secret presented in the request header, or '' when absent.
 * @return int HTTP status the request must be answered with: 404, 405, 403 or 202.
 */
function wp_perf_cache_reset_status( $method, $presented ) {
	$token = wp_perf_cache_reset_token();

	if ( '' === $token ) {
		return 404;
	}

	if ( ! is_string( $method ) || 'POST' !== strtoupper( $method ) ) {
		return 405;
	}

	if ( ! is_string( $presented ) || '' === $presented || ! hash_equals( $token, $presented ) ) {
		return 403;
	}

	return 202;
}

/**
 * Discards every cache the next measured request would otherwise be served from.
 *
 * Each sample is measured in the cold, uncached regime, so the opcode cache, the
 * object cache and the expired transient rows are discarded before the request under
 * measurement starts. The reset lives in this file because it is the one mu-plugin the
 * performance workflows copy into the installed tree.
 *
 * Reached only from the control plane below, and only for a POST that presented the
 * provisioned token. `clear-cache.php` in this directory runs the same operations for
 * any request carrying the query argument, and must-use plugins load in filename
 * order, so an installation holding both would let that file answer first with an
 * unauthenticated reset. This mu-plugin has to be provisioned without it.
 *
 * @ignore
 * @since 7.0.0
 * @access private
 *
 * @return void
 */
function wp_perf_reset_caches() {
	$reset = array();

	if ( function_exists( 'opcache_reset' ) ) {
		// Returns false when OPcache is disabled or restricted, which is not an error here.
		if ( opcache_reset() ) {
			$reset[] = 'opcache';
		}
	}

	if ( function_exists( 'apcu_clear_cache' ) ) {
		apcu_clear_cache();
		$reset[] = 'apcu';
	}

	wp_cache_flush();
	$reset[] = 'object-cache';

	delete_expired_transients( true );
	$reset[] = 'transients';

	clearstatcache( true );
	$reset[] = 'stat';

	/*
	 * Reported so a spec can assert on what was actually discarded rather than only
	 * on the status code. The header carries a fixed vocabulary of operation names,
	 * no request data and no part of the token. It is sent on the authorized 202
	 * only: an unauthenticated caller receives a bare 404, 405 or 403 and therefore
	 * cannot use this vocabulary to learn which cache layers the host runs.
	 */
	header( 'X-WP-Perf-Cache-Reset: ' . implode( ',', $reset ) );

	/*
	 * 202 rather than 200, because nothing was rendered and the only thing the
	 * caller may conclude is that the reset was accepted. It is this control plane's
	 * explicit success signal, and it is none of the statuses the ladder above
	 * refuses with, so a spec that requires it fails rather than going on to measure
	 * a warm request.
	 */
	status_header( 202 );

	die;
}

/*
 * The cache reset control plane.
 *
 * Registered at 'plugins_loaded' priority 1, so the reset runs and the request ends
 * before the request the harness goes on to measure, and at the priority the
 * pre-existing clear-cache.php uses so the load order of the two files is unchanged.
 *
 * The `clear_cache` query argument selects the endpoint and authorizes nothing, so the
 * URL the workflows and the specs use is unchanged. Authorization is the token in the
 * X-WP-Perf-Cache-Reset-Token request header, which arrives as
 * $_SERVER['HTTP_X_WP_PERF_CACHE_RESET_TOKEN']: a header cannot be set by a
 * cross-origin form, a navigation or an embedded resource, and it keeps the secret out
 * of the address, the referrer and the browser history. Neither superglobal is trusted
 * beyond an isset() test before it has been unslashed and sanitized.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! isset( $_GET['clear_cache'] ) ) {
			return;
		}

		// sanitize_key() also lower-cases; wp_perf_cache_reset_status() compares case-insensitively.
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: '';

		$presented = isset( $_SERVER['HTTP_X_WP_PERF_CACHE_RESET_TOKEN'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_PERF_CACHE_RESET_TOKEN'] ) )
			: '';

		$status = wp_perf_cache_reset_status( $method, $presented );

		if ( 202 === $status ) {
			wp_perf_reset_caches();
		}

		/*
		 * Refused. Only the status is reported: no body, no reset vocabulary header, no
		 * echo of what was presented and no hint about which step refused beyond the
		 * status code itself.
		 */
		status_header( $status );

		die;
	},
	1
);

/**
 * Stores or retrieves the duration of the WordPress bootstrap sequence, in seconds.
 *
 * The metric has exactly one boundary: the interval from $timestart to 'wp_loaded',
 * which is the same boundary in the front-end and the admin scenario, so the two are
 * comparable. A request that never reaches 'wp_loaded' has no bootstrap duration at
 * all rather than one measured to a different end point, and that is reported as null.
 *
 * Both collectors below register their 'shutdown' callback from a hook that runs after
 * 'wp_loaded' - the template filter on the front end and admin_init in the admin - so
 * a collector that runs at all runs with the duration already recorded. The null is
 * guarded rather than assumed and emitted as 0.0, a value no measured bootstrap can be
 * confused with, rather than reaching the header conversion.
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
 * only genuinely public properties and no magic accessor is ever consulted. A public
 * counter that is a finite numeric value within the integer range is cast to an integer,
 * so a fractional value is truncated; anything else - absent, non-numeric, non-finite or
 * out of range - is reported as 0, so the metric degrades without ever being omitted.
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
		 * A numeric string or a float is still usable, but only once it is known to be
		 * finite and within the integer range: casting a non-finite or out-of-range
		 * float can emit a conversion diagnostic, which is exactly the kind of notice
		 * this function exists to keep out of the measured response.
		 */
		$counter_number = (float) $counter_value;

		if ( is_finite( $counter_number ) && $counter_number >= (float) PHP_INT_MIN && $counter_number < (float) PHP_INT_MAX ) {
			$counters[ $counter ] = (int) $counter_number;
		}
	}

	return $counters;
}

/**
 * Converts a Server-Timing value to the unit emitted in the response header.
 *
 * Only durations are stored internally as seconds. Counts, flags and byte sizes must
 * pass through unchanged even when represented as floats.
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

		/*
		 * Every metric below is sampled in the first 'shutdown' callback, and that is
		 * the boundary each of them describes.
		 *
		 * PHP_INT_MIN makes this callback first, which it has to be: the Server-Timing
		 * header can only be sent while nothing has flushed the buffer opened above, so
		 * the callback takes the body with ob_get_clean(), sets the header, and echoes
		 * the body back out. The remaining shutdown callbacks and PHP's own shutdown
		 * work run afterwards and are outside the measurement.
		 *
		 * So 'files-loaded', 'memory-peak', 'db-queries', 'cache-hits', 'cache-misses',
		 * 'total' and 'template' are values as at that point rather than whole-process
		 * totals: a file included by a later shutdown callback is not counted, and
		 * neither is the memory it allocates. A before/after comparison of these
		 * figures therefore covers that common pre-boundary interval only.
		 */
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

				/*
				 * This callback is registered from the template filter, which runs after
				 * 'wp_loaded' has fired, so the duration is recorded by the time it runs.
				 * The null the function reserves for a request that never reached that
				 * boundary is still guarded rather than assumed: it is emitted as a
				 * duration of 0.0, which no sample can be confused with, instead of
				 * raising a conversion notice from inside a callback that has already
				 * taken the response body.
				 */
				$bootstrap = wp_perf_bootstrap_duration();

				/*
				 * While values passed via Server-Timing are intended to be durations,
				 * any numeric value can actually be passed.
				 * This is a nice little trick as it allows to easily get this information in JS.
				 */
				$server_timing_values['memory-usage']  = memory_get_usage();
				$server_timing_values['db-queries']    = $wpdb->num_queries;
				$server_timing_values['ext-obj-cache'] = wp_using_ext_object_cache() ? 1 : 0;
				$server_timing_values['memory-peak']   = $memory_peak;
				$server_timing_values['files-loaded']  = (int) count( get_included_files() );
				$server_timing_values['cache-hits']    = $cache_counters['hits'];
				$server_timing_values['cache-misses']  = $cache_counters['misses'];
				$server_timing_values['bootstrap']     = null === $bootstrap ? 0.0 : $bootstrap;

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

		/*
		 * The same boundary as the front-end collector above, for the same reason:
		 * PHP_INT_MIN makes this the first 'shutdown' callback, so every metric below
		 * describes the request as it stood at the start of shutdown rather than at the
		 * end of the process, and the header is sent before anything can flush the
		 * buffer opened above.
		 */
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

				/*
				 * Recorded by the time this runs, for the same reason as in the front-end
				 * collector above: this callback is registered from 'admin_init', which
				 * runs after 'wp_loaded' fired. The null is still guarded rather than
				 * assumed.
				 */
				$bootstrap = wp_perf_bootstrap_duration();

				/*
				 * While values passed via Server-Timing are intended to be durations,
				 * any numeric value can actually be passed.
				 * This is a nice little trick as it allows to easily get this information in JS.
				 */
				$server_timing_values['memory-usage']  = memory_get_usage();
				$server_timing_values['db-queries']    = $wpdb->num_queries;
				$server_timing_values['ext-obj-cache'] = wp_using_ext_object_cache() ? 1 : 0;
				$server_timing_values['memory-peak']   = $memory_peak;
				$server_timing_values['files-loaded']  = (int) count( get_included_files() );
				$server_timing_values['cache-hits']    = $cache_counters['hits'];
				$server_timing_values['cache-misses']  = $cache_counters['misses'];
				$server_timing_values['bootstrap']     = null === $bootstrap ? 0.0 : $bootstrap;

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
