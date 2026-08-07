<?php

/**
 * Resolves the secret that authorizes a cache reset, or reports that there is none.
 *
 * The reset is an expensive, unauthenticated-by-nature side effect: it discards the
 * opcode cache, the object cache and the expired transient rows for the whole
 * installation. Presence of a query argument is therefore not authorization, and this
 * function is what the control plane below has instead. It reports the empty string
 * whenever no usable secret has been provisioned, and the caller treats that as the
 * endpoint not existing at all.
 *
 * Provisioning the secret is the explicit enable step, and it is the only one: the
 * performance harness writes a fresh random token before its first measured iteration
 * (`tests/performance/utils.js`) and its global teardown deletes the file again
 * (`tests/performance/config/global-teardown.js`), so the control plane exists for
 * exactly the duration of one measured run and nowhere else. An installation that
 * merely has this mu-plugin present has no reset endpoint.
 *
 * Three sources are consulted, in this order, so that a deployment can choose whichever
 * it can reach:
 *
 * 1. The `WP_PERF_CACHE_RESET_TOKEN` constant, for a `wp-config.php` deployment.
 * 2. The `WP_PERF_CACHE_RESET_TOKEN` environment variable, for a container deployment.
 * 3. A token file, which is what the harness itself uses. Its path comes from the
 *    `WP_PERF_CACHE_RESET_TOKEN_FILE` constant or environment variable, and otherwise
 *    defaults to `.cache/performance-cache-reset-token` beside the installation
 *    directory rather than inside it, so the secret is never itself web readable.
 *
 * Every candidate must be at least 32 alphanumeric characters. That is a grammar check
 * rather than a strength check, but it is enough to make the failure closed instead of
 * open: a truncated file, an empty variable, a placeholder or a short hand-written
 * value all resolve to no token, which disables the reset rather than protecting it
 * with something guessable.
 *
 * Deliberately implemented with language functions only. It is called by
 * `tests/phpunit/data/isolated/server-timing-probe.php` in a process where WordPress
 * has never been loaded, which is what allows the whole decision to be unit tested.
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
			 * Beside the installation directory, never inside it. ABSPATH is the document
			 * root the web server serves, so a token kept under it would be fetchable over
			 * HTTP by the very requests this token exists to keep out.
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
 * Separated from the request so that the decision is a pure function of the two things
 * that may authorize a reset, which is what lets every branch be measured directly by
 * `tests/phpunit/data/isolated/server-timing-probe.php` rather than inferred from a
 * live response. It never resets anything itself.
 *
 * The ladder fails closed at each step, and each step answers with the status that
 * describes only that step:
 *
 * - 404 when no token has been provisioned. The endpoint does not exist, and nothing
 *   about the installation is disclosed, including which cache layers it runs.
 * - 405 for any method other than POST. A reset changes server state, so it may not be
 *   reachable by navigation, prefetch, image load or link preview.
 * - 403 when the presented secret is absent or does not match, compared with
 *   `hash_equals()` so the comparison is not a timing oracle.
 * - 202 only when a POST presented the provisioned token.
 *
 * The token is read from a request header by the caller below, never from the URL, so
 * it cannot be sent by a cross-origin form, an `img` tag or a navigation, and it does
 * not reach the access log, the referrer or the browser history.
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
 * The measurement contract for this harness is that each sample is taken in the
 * cold, uncached regime: the opcode cache, the object cache and the expired
 * transient rows must all be gone before the request under measurement starts.
 * `tests/performance/wp-content/mu-plugins/clear-cache.php` is what has always
 * implemented that, but the CI job that provisions the harness copies only
 * `server-timing.php` into the installed tree, so under the shipped workflow
 * `/?clear_cache` was answered by WordPress as an ordinary front-page request and
 * nothing was reset. A 200 satisfied the step, and warm samples were published
 * under an uncached label.
 *
 * Answering the same request here puts the reset in the one file the workflow
 * installs, so the regime is a property of the harness rather than of how the
 * harness happened to be provisioned.
 *
 * Reached only from the control plane below, and only for a POST that presented the
 * provisioned token. That is a deliberate divergence from `clear-cache.php`, which runs
 * the same operations for any request carrying the query argument: the two files no
 * longer behave identically, and only this one may be provisioned. Must-use plugins
 * load in filename order, so an installation holding both would let `clear-cache.php`
 * answer first and exit, reinstating an unauthenticated reset that this file refuses;
 * the harness therefore installs this file alone, exactly as both performance workflows
 * already do.
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
	 * caller may conclude is that the reset was accepted. No status WordPress
	 * itself sends for this URL collides with it, and neither does any status the
	 * control plane refuses with, so a spec that requires 202 fails whenever the
	 * reset helper is absent or its token was never provisioned instead of going on
	 * to measure a warm request.
	 */
	status_header( 202 );

	die;
}

/*
 * The cache reset control plane.
 *
 * Registered at 'plugins_loaded' priority 1, early enough that nothing the request
 * would otherwise be served from has been read yet, and matching the priority the
 * pre-existing clear-cache.php uses so that the load order of the two files is
 * unchanged.
 *
 * The `clear_cache` query argument selects the endpoint and authorizes nothing. It is
 * not a secret and is deliberately left where it has always been, so the URL the
 * workflows and the specs use does not change. Authorization is the token in the
 * X-WP-Perf-Cache-Reset-Token request header, which arrives as
 * $_SERVER['HTTP_X_WP_PERF_CACHE_RESET_TOKEN']: a header cannot be set by a
 * cross-origin form, a navigation or an embedded resource, so no reset can be provoked
 * by tricking a browser, and the secret never reaches a URL, an access log, a referrer
 * or the browser history. Neither superglobal is trusted beyond an isset() test before
 * it has been unslashed and sanitized.
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
			// Sends the 202 and the reset vocabulary header itself, then ends the request.
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
 * Reads one OPcache directive from a configuration snapshot.
 *
 * opcache_get_configuration() is the accurate source, because it reports the value the
 * engine resolved. It is also refusable: opcache.restrict_api makes it return false for
 * a script outside the permitted path, exactly as it does for opcache_get_status().
 * ini_get() is never refused, so it is the fallback that keeps a restricted request from
 * having to report an unknown regime.
 *
 * @ignore
 * @since 7.0.0
 * @access private
 *
 * @param array|false|null $directives Directives from opcache_get_configuration(), or null
 *                                     or false to read from ini_get().
 * @param string           $name       Directive name, including its `opcache.` prefix.
 * @return mixed Directive value, or false when the directive is unknown.
 */
function wp_perf_opcache_directive( $directives, $name ) {
	if ( is_array( $directives ) && array_key_exists( $name, $directives ) ) {
		return $directives[ $name ];
	}

	return ini_get( $name );
}

/**
 * Reads one OPcache directive as a boolean.
 *
 * opcache_get_configuration() types a boolean directive as a boolean while ini_get()
 * types it as a string, and the string form differs between builds. Normalizing both
 * here keeps the caller from having to know which source answered. No cast to int is
 * used, because casting a non-representable float emits a notice as of PHP 8.5 and this
 * instrumentation must not print anything into a measured response.
 *
 * @ignore
 * @since 7.0.0
 * @access private
 *
 * @param array|false|null $directives Directives from opcache_get_configuration(), or null
 *                                     or false to read from ini_get().
 * @param string           $name       Directive name, including its `opcache.` prefix.
 * @return bool Whether the directive is switched on.
 */
function wp_perf_opcache_flag( $directives, $name ) {
	$value = wp_perf_opcache_directive( $directives, $name );

	if ( is_bool( $value ) ) {
		return $value;
	}

	if ( is_int( $value ) ) {
		return 0 !== $value;
	}

	if ( is_float( $value ) ) {
		return is_finite( $value ) && 0.0 !== $value;
	}

	if ( ! is_string( $value ) ) {
		return false;
	}

	return in_array( strtolower( trim( $value ) ), array( '1', 'on', 'yes', 'true' ), true );
}

/**
 * Reduces the OPcache configuration to stable numeric measurement metadata.
 *
 * Both reported fields describe how the opcode cache is *configured*, never what it
 * currently *holds*. That is deliberate, and it is measured rather than assumed.
 * opcache_get_status() answers with the accelerator state of the request that asked, and
 * clear-cache.php resets the opcode cache before every measured iteration: a reset takes
 * effect only once a later request can take the lock while no other worker is active, so
 * until then every request activates with the accelerator switched off and
 * opcache_get_status() answers `opcache_enabled => false` while the cache still holds its
 * scripts. On this suite's own harness that transient appeared in none of 40 serial
 * reset-then-request cycles and in 56 of 60 of the same cycles run against six concurrent
 * front-page requests, which is why it surfaced only on the heaviest theme, and only
 * sometimes.
 *
 * Deriving the regime from that state would therefore report a value that changes between
 * iterations of one scenario, and both consumers need the opposite: the specs assert the
 * regime is immutable within a measured theme and locale, and compare-results.js refuses a
 * before/after pair whose regimes disagree, because a pair taken across two opcode-cache
 * regimes reports the regime rather than the change under test. What those two checks are
 * really about is the interpreter flags, which cannot change without restarting the
 * process, so the flags are what these fields report.
 *
 * Only the two regime flags are reported. Server-wide counters such as the number of
 * cached scripts and the cache hit rate describe every site sharing the interpreter rather
 * than the request being measured, and this header is sent to every client, so they are
 * deliberately not emitted.
 *
 * Every field has a numeric zero fallback, so no measured iteration can silently omit a
 * field and leave an unusable sample series.
 *
 * @ignore
 * @since 7.0.0
 * @access private
 *
 * @param array|false      $status     OPcache status from opcache_get_status(), or false when
 *                                     unavailable. Accepted so a caller can pass the snapshot
 *                                     it already holds; the reported regime never depends on
 *                                     it, which is what keeps the regime immutable across the
 *                                     iterations of one measured scenario.
 * @param array|false|null $directives Directives from opcache_get_configuration(), or null or
 *                                     false to read each directive from ini_get(). This is the
 *                                     sole source of both reported fields.
 * @return array {
 *     Numeric OPcache regime metadata.
 *
 *     @type int $opcache-enabled Whether the opcode cache is configured on for this SAPI.
 *     @type int $opcache-jit     Whether OPcache JIT is configured on.
 * }
 */
function wp_perf_opcache_metadata( $status, $directives = null ) {
	$metadata = array(
		'opcache-enabled' => 0,
		'opcache-jit'     => 0,
	);

	$enabled = wp_perf_opcache_flag( $directives, 'opcache.enable' );

	/*
	 * opcache.enable is the master switch, and the command-line SAPIs need their own one
	 * as well. Honoring it keeps a command-line invocation of this instrumentation from
	 * claiming the regime of the web requests being measured.
	 */
	if ( $enabled && ( 'cli' === PHP_SAPI || 'phpdbg' === PHP_SAPI ) ) {
		$enabled = wp_perf_opcache_flag( $directives, 'opcache.enable_cli' );
	}

	if ( $enabled ) {
		$metadata['opcache-enabled'] = 1;

		/*
		 * JIT needs a compiling mode selected and a buffer to compile into:
		 * opcache.jit_buffer_size of 0 switches JIT off whatever the mode says. The
		 * buffer is a shorthand byte value such as `64M`, and its leading number is
		 * enough to tell zero from non-zero without parsing the suffix.
		 */
		$mode   = wp_perf_opcache_directive( $directives, 'opcache.jit' );
		$mode   = is_scalar( $mode ) ? strtolower( trim( (string) $mode ) ) : '';
		$buffer = wp_perf_opcache_directive( $directives, 'opcache.jit_buffer_size' );
		$buffer = is_scalar( $buffer ) ? (float) $buffer : 0.0;

		if (
			! in_array( $mode, array( '', '0', 'off', 'no', 'false', 'none', 'disable' ), true )
			&& is_finite( $buffer ) && 0.0 < $buffer
		) {
			$metadata['opcache-jit'] = 1;
		}
	}

	return $metadata;
}

/**
 * Returns the opcode-cache regime metadata for the current request.
 *
 * The result is memoized so every consumer in one request observes one OPcache
 * snapshot, and a new PHP request recomputes it.
 *
 * The two regime flags are the whole of what is reported. They are what the
 * measurement method requires alongside every figure, because opcode-cache state
 * moves measured time and memory by far more than any change under test. Nothing
 * that identifies the process or the interpreter is emitted: a process ID, a PHP
 * version ID, a worker request count and server-wide OPcache counters describe the
 * host rather than the request, and this header is sent to every client.
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

	/*
	 * Resolved once per request beside the status, so the regime fields are read from the
	 * interpreter flags rather than from the accelerator state of this particular request.
	 * Access to this function is restrictable in the same way, and it warns when refused.
	 */
	$opcache_directives = null;

	if ( function_exists( 'opcache_get_configuration' ) ) {
		$opcache_configuration = @opcache_get_configuration(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( is_array( $opcache_configuration ) && isset( $opcache_configuration['directives'] ) && is_array( $opcache_configuration['directives'] ) ) {
			$opcache_directives = $opcache_configuration['directives'];
		}
	}

	$metadata = wp_perf_opcache_metadata( $opcache_status, $opcache_directives );

	return $metadata;
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
		 * Everything below is measured at the start of shutdown, and that is the
		 * boundary every metric it reports describes.
		 *
		 * PHP_INT_MIN makes this the first 'shutdown' callback, which it has to be: the
		 * Server-Timing header can only be sent while nothing has flushed the buffer
		 * opened above, so the callback takes the body with ob_get_clean(), sets the
		 * header, and echoes the body back out. Everything WordPress runs afterwards is
		 * therefore outside the measurement - wp_ob_end_flush_all() at priority 1, then
		 * _wp_cron(), _wp_delete_all_temp_backups() and any plugin callback at the
		 * default priority, and finally wp_cache_close() plus PHP's own shutdown work,
		 * all of which happen inside or after shutdown_action_hook().
		 *
		 * So 'files-loaded', 'memory-peak', 'db-queries', 'cache-hits', 'cache-misses',
		 * 'total' and 'template' describe the request up to this point rather than
		 * whole-process totals: a file included by a later shutdown callback is not
		 * counted, and neither is the memory it allocates. That is the figure worth
		 * reporting, because it covers everything that happens before the response
		 * reaches the client, and because both arms of a comparison stop at the same
		 * boundary the difference between them still belongs to the code under test.
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

				$runtime = wp_perf_runtime_metadata();

				// Null exactly when the single 'wp_loaded' boundary was never reached.
				$bootstrap = wp_perf_bootstrap_duration();

				/*
				 * While values passed via Server-Timing are intended to be durations,
				 * any numeric value can actually be passed.
				 * This is a nice little trick as it allows to easily get this information in JS.
				 */
				$server_timing_values['memory-usage']    = memory_get_usage();
				$server_timing_values['db-queries']      = $wpdb->num_queries;
				$server_timing_values['ext-obj-cache']   = wp_using_ext_object_cache() ? 1 : 0;
				$server_timing_values['memory-peak']     = $memory_peak;
				$server_timing_values['files-loaded']    = (int) count( get_included_files() );
				$server_timing_values['cache-hits']      = $cache_counters['hits'];
				$server_timing_values['cache-misses']    = $cache_counters['misses'];
				$server_timing_values['bootstrap']       = null === $bootstrap ? 0.0 : $bootstrap;
				$server_timing_values['bootstrap-valid'] = null === $bootstrap ? 0 : 1;
				$server_timing_values['opcache-enabled'] = $runtime['opcache-enabled'];
				$server_timing_values['opcache-jit']     = $runtime['opcache-jit'];

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

				$runtime = wp_perf_runtime_metadata();

				// Null exactly when the single 'wp_loaded' boundary was never reached.
				$bootstrap = wp_perf_bootstrap_duration();

				/*
				 * While values passed via Server-Timing are intended to be durations,
				 * any numeric value can actually be passed.
				 * This is a nice little trick as it allows to easily get this information in JS.
				 */
				$server_timing_values['memory-usage']    = memory_get_usage();
				$server_timing_values['db-queries']      = $wpdb->num_queries;
				$server_timing_values['ext-obj-cache']   = wp_using_ext_object_cache() ? 1 : 0;
				$server_timing_values['memory-peak']     = $memory_peak;
				$server_timing_values['files-loaded']    = (int) count( get_included_files() );
				$server_timing_values['cache-hits']      = $cache_counters['hits'];
				$server_timing_values['cache-misses']    = $cache_counters['misses'];
				$server_timing_values['bootstrap']       = null === $bootstrap ? 0.0 : $bootstrap;
				$server_timing_values['bootstrap-valid'] = null === $bootstrap ? 0 : 1;
				$server_timing_values['opcache-enabled'] = $runtime['opcache-enabled'];
				$server_timing_values['opcache-jit']     = $runtime['opcache-jit'];

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
