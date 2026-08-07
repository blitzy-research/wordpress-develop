<?php
/**
 * Reports what the performance mu-plugin's metric helpers do for one fixture, in a fresh process.
 *
 * The mu-plugin cannot be loaded by the test suite. Requiring it registers callbacks that call
 * ob_start() on 'template_include' and 'admin_init' and flush the buffer from 'shutdown', which
 * is why it is kept out of src/wp-content/mu-plugins while PHPUnit runs. It also holds the
 * recorded bootstrap duration in a static, so the value a fresh request starts from is only
 * observable in a process that has not recorded one yet.
 *
 * This probe loads the mu-plugin with add_action() and add_filter() defined as recorders, so no
 * hook is ever really registered and nothing can buffer or emit anything, and then exercises the
 * two helpers directly against one fixture.
 *
 * Usage:
 *
 *     php server-timing-probe.php <mu-plugin-file> <fixture>
 *
 * The fixture `list` reports the fixtures this probe knows, so the caller can prove its own
 * expectations cover exactly them and no more.
 *
 * The only thing written to standard output is a single JSON object, so the caller can treat any
 * other output, and anything at all on standard error, as a failure. Output produced while a
 * helper runs is captured rather than printed and reported as a length, because the helpers are
 * called from a 'shutdown' callback that has already taken the response body out of the output
 * buffer: anything they emit would be appended to a finished response. Diagnostics are captured
 * the same way and reported, rather than being allowed to become output, so that a notice is
 * reported as a notice instead of arriving as corruption.
 *
 * Nothing here loads WordPress, connects to a database or reads the request, so there is nothing
 * for it to leave behind.
 *
 * @package WordPress
 * @subpackage UnitTests
 */

if ( ! isset( $argv[1], $argv[2] ) ) {
	fwrite( STDERR, "Usage: php server-timing-probe.php <mu-plugin-file> <fixture>\n" );
	exit( 1 );
}

$GLOBALS['wp_perf_probe_diagnostics']   = array();
$GLOBALS['wp_perf_probe_magic_calls']   = array();
$GLOBALS['wp_perf_probe_registrations'] = array();

error_reporting( E_ALL );
ini_set( 'display_errors', '0' );

/*
 * Records every diagnostic instead of letting it print. Returning true keeps PHP's own handler
 * out of it, so a notice raised by reading a counter is reported in the JSON rather than being
 * written into the response the caller is measuring.
 */
set_error_handler(
	static function ( $errno, $errstr ) {
		$GLOBALS['wp_perf_probe_diagnostics'][] = $errno . ': ' . $errstr;

		return true;
	}
);

/**
 * Records an action registration in place of registering it.
 *
 * @param string   $hook_name     Hook name.
 * @param callable $callback      Callback. Recorded only by shape, never called.
 * @param int      $priority      Optional. Priority. Default 10.
 * @param int      $accepted_args Optional. Number of accepted arguments. Default 1.
 * @return true Always true, matching the real function.
 */
function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook_name, $callback, $priority, $accepted_args );
}

/**
 * Records a filter registration in place of registering it.
 *
 * @param string   $hook_name     Hook name.
 * @param callable $callback      Callback. Recorded only by shape, never called.
 * @param int      $priority      Optional. Priority. Default 10.
 * @param int      $accepted_args Optional. Number of accepted arguments. Default 1.
 * @return true Always true, matching the real function.
 */
function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['wp_perf_probe_registrations'][] = array(
		'hook'          => $hook_name,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
		'callable'      => is_callable( $callback ),
	);

	return true;
}

/**
 * An object cache whose counters are private, as a drop-in is free to make them.
 */
class WP_Perf_Probe_Private_Counters {

	/**
	 * Hits, not readable from outside the class.
	 *
	 * @var int
	 */
	private $cache_hits = 11;

	/**
	 * Misses, not readable from outside the class.
	 *
	 * @var int
	 */
	private $cache_misses = 22;

	/**
	 * A public property, so the snapshot the helper takes is not empty for the wrong reason.
	 *
	 * @var string
	 */
	public $group_prefix = 'private';
}

/**
 * An object cache whose counters are protected, as a drop-in is free to make them.
 */
class WP_Perf_Probe_Protected_Counters {

	/**
	 * Hits, not readable from outside the class.
	 *
	 * @var int
	 */
	protected $cache_hits = 11;

	/**
	 * Misses, not readable from outside the class.
	 *
	 * @var int
	 */
	protected $cache_misses = 22;

	/**
	 * A public property, so the snapshot the helper takes is not empty for the wrong reason.
	 *
	 * @var string
	 */
	public $group_prefix = 'protected';
}

/**
 * An object cache that would serve counters through magic accessors.
 *
 * Both accessors record that they ran and offer a value that no other fixture uses, so the
 * caller can tell "the accessors were never consulted" apart from "they were consulted and
 * happened to agree".
 */
class WP_Perf_Probe_Magic_Counters {

	/**
	 * A public property, so the snapshot the helper takes is not empty for the wrong reason.
	 *
	 * @var string
	 */
	public $group_prefix = 'magic';

	/**
	 * Records the read and offers a distinctive value.
	 *
	 * @param string $property Property name.
	 * @return int Always 99.
	 */
	public function __get( $property ) {
		$GLOBALS['wp_perf_probe_magic_calls'][] = '__get:' . $property;

		return 99;
	}

	/**
	 * Records the test and claims every property exists.
	 *
	 * @param string $property Property name.
	 * @return true Always true.
	 */
	public function __isset( $property ) {
		$GLOBALS['wp_perf_probe_magic_calls'][] = '__isset:' . $property;

		return true;
	}
}

/**
 * An object cache with public counters, holding whatever the fixture needs them to hold.
 */
class WP_Perf_Probe_Public_Counters {

	/**
	 * Hits.
	 *
	 * @var mixed
	 */
	public $cache_hits;

	/**
	 * Misses.
	 *
	 * @var mixed
	 */
	public $cache_misses;

	/**
	 * Sets the counters.
	 *
	 * @param mixed $hits   Value for the hits counter.
	 * @param mixed $misses Value for the misses counter.
	 */
	public function __construct( $hits, $misses ) {
		$this->cache_hits   = $hits;
		$this->cache_misses = $misses;
	}
}

/**
 * An object cache with neither counter, as a drop-in that does not track them would be.
 */
class WP_Perf_Probe_Counters_Absent {

	/**
	 * A public property, so the snapshot the helper takes is not empty for the wrong reason.
	 *
	 * @var string
	 */
	public $group_prefix = 'no-counters';
}

/**
 * Encodes the report, failing loudly rather than printing half of one.
 *
 * @param array $report Report to encode.
 * @return string JSON.
 */
function wp_perf_probe_encode( $report ) {
	$json = json_encode( $report );

	if ( false === $json ) {
		fwrite( STDERR, 'Cannot encode the report: ' . json_last_error_msg() . "\n" );
		exit( 1 );
	}

	return $json;
}

/**
 * Describes a value the way the caller can assert on it, whatever JSON does to numbers.
 *
 * @param mixed $value Value to describe.
 * @return array The value as a string, and its PHP type.
 */
function wp_perf_probe_describe( $value ) {
	return array(
		'value' => is_float( $value ) ? sprintf( '%.10F', $value ) : (string) $value,
		'type'  => gettype( $value ),
	);
}

/**
 * Builds the object cache global for one fixture.
 *
 * @param string $fixture Fixture name.
 * @return array Two entries: whether to set the global at all, and the value to set it to.
 */
function wp_perf_probe_fixture( $fixture ) {
	switch ( $fixture ) {
		case 'absent':
			return array( false, null );
		case 'global-is-null':
			return array( true, null );
		case 'global-is-array':
			return array(
				true,
				array(
					'cache_hits'   => 5,
					'cache_misses' => 6,
				),
			);
		case 'global-is-string':
			return array( true, 'not an object' );
		case 'no-counter-properties':
			return array( true, new WP_Perf_Probe_Counters_Absent() );
		case 'private-counters':
			return array( true, new WP_Perf_Probe_Private_Counters() );
		case 'protected-counters':
			return array( true, new WP_Perf_Probe_Protected_Counters() );
		case 'magic-counters':
			return array( true, new WP_Perf_Probe_Magic_Counters() );
		case 'integer-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( 12, 34 ) );
		case 'zero-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( 0, 0 ) );
		case 'negative-integer-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( -5, -7 ) );
		case 'numeric-string-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( '12', '34' ) );
		case 'leading-whitespace-string-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( ' 12', ' 34' ) );
		case 'exponent-string-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( '1e3', '2e3' ) );
		case 'hex-string-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( '0x1A', '0x2B' ) );
		case 'fractional-float-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( 12.9, 34.2 ) );
		case 'negative-fractional-float-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( -12.9, -34.2 ) );
		case 'nonnumeric-string-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( 'many', 'lots' ) );
		case 'empty-string-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( '', '' ) );
		case 'boolean-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( true, false ) );
		case 'null-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( null, null ) );
		case 'array-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( array( 1, 2 ), array( 3 ) ) );
		case 'object-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( new stdClass(), new stdClass() ) );
		case 'infinite-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( INF, -INF ) );
		case 'nan-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( NAN, NAN ) );
		case 'out-of-range-high-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( 1.0e30, 2.0e30 ) );
		case 'out-of-range-low-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( -1.0e30, -2.0e30 ) );
		case 'int-max-integer-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( PHP_INT_MAX, PHP_INT_MAX ) );
		case 'int-max-float-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( (float) PHP_INT_MAX, (float) PHP_INT_MAX ) );
		case 'int-min-float-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( (float) PHP_INT_MIN, (float) PHP_INT_MIN ) );
		case 'mixed-counters':
			return array( true, new WP_Perf_Probe_Public_Counters( 12, 'many' ) );
		case 'bootstrap-duration':
		case 'measurement-metadata':
			return array( false, null );
	}

	fwrite( STDERR, "Unknown fixture {$fixture}.\n" );
	exit( 1 );
}

$wp_perf_probe_fixtures = array(
	'absent',
	'global-is-null',
	'global-is-array',
	'global-is-string',
	'no-counter-properties',
	'private-counters',
	'protected-counters',
	'magic-counters',
	'integer-counters',
	'zero-counters',
	'negative-integer-counters',
	'numeric-string-counters',
	'leading-whitespace-string-counters',
	'exponent-string-counters',
	'hex-string-counters',
	'fractional-float-counters',
	'negative-fractional-float-counters',
	'nonnumeric-string-counters',
	'empty-string-counters',
	'boolean-counters',
	'null-counters',
	'array-counters',
	'object-counters',
	'infinite-counters',
	'nan-counters',
	'out-of-range-high-counters',
	'out-of-range-low-counters',
	'int-max-integer-counters',
	'int-max-float-counters',
	'int-min-float-counters',
	'mixed-counters',
	'bootstrap-duration',
	'measurement-metadata',
);

if ( 'list' === $argv[2] ) {
	echo wp_perf_probe_encode( array( 'fixtures' => $wp_perf_probe_fixtures ) );
	exit( 0 );
}

require $argv[1];

list( $wp_perf_probe_set_global, $wp_perf_probe_value ) = wp_perf_probe_fixture( $argv[2] );

if ( $wp_perf_probe_set_global ) {
	$GLOBALS['wp_object_cache'] = $wp_perf_probe_value;
} else {
	unset( $GLOBALS['wp_object_cache'] );
}

/*
 * The helpers are called with output buffered so that anything they print is reported as a
 * length instead of joining this probe's own output, which is the JSON report.
 */
ob_start();

$wp_perf_probe_counters = wp_perf_object_cache_counters();

$wp_perf_probe_bootstrap = array();

if ( 'bootstrap-duration' === $argv[2] ) {
	/*
	 * Read before write, so the value a fresh request starts from is observed rather than
	 * assumed, then each write and the read that follows it.
	 */
	$wp_perf_probe_bootstrap['initial']            = wp_perf_probe_describe( wp_perf_bootstrap_duration() );
	$wp_perf_probe_bootstrap['after_float']        = wp_perf_probe_describe( wp_perf_bootstrap_duration( 1.5 ) );
	$wp_perf_probe_bootstrap['reread']             = wp_perf_probe_describe( wp_perf_bootstrap_duration() );
	$wp_perf_probe_bootstrap['after_integer']      = wp_perf_probe_describe( wp_perf_bootstrap_duration( 2 ) );
	$wp_perf_probe_bootstrap['after_string']       = wp_perf_probe_describe( wp_perf_bootstrap_duration( '3.25' ) );
	$wp_perf_probe_bootstrap['after_zero']         = wp_perf_probe_describe( wp_perf_bootstrap_duration( 0 ) );
	$wp_perf_probe_bootstrap['reread_after_zero']  = wp_perf_probe_describe( wp_perf_bootstrap_duration() );
}

$wp_perf_probe_metadata = array();

if ( 'measurement-metadata' === $argv[2] ) {
	$wp_perf_probe_metadata['runtime_first']  = array_map( 'wp_perf_probe_describe', wp_perf_runtime_metadata() );
	$wp_perf_probe_metadata['runtime_reread'] = array_map( 'wp_perf_probe_describe', wp_perf_runtime_metadata() );

	/*
	 * The regime fields come from the interpreter flags and the diagnostic fields from the
	 * status, and these cases hold those two axes apart. The one that matters most is the
	 * inactive accelerator: the suite resets the opcode cache before every measured
	 * iteration, a reset is only performed once a request can take the lock with no other
	 * worker active, and every request arriving before that reports an inactive accelerator
	 * while the cache still holds its scripts. Reading the regime from there would make it
	 * change between iterations of one scenario.
	 */
	$wp_perf_probe_opcache_status = array(
		'opcache_enabled'    => true,
		'jit'                => array(
			'on' => true,
		),
		'opcache_statistics' => array(
			'num_cached_scripts' => '321',
			'opcache_hit_rate'   => 98.7654,
		),
	);

	$wp_perf_probe_opcache_inactive                    = $wp_perf_probe_opcache_status;
	$wp_perf_probe_opcache_inactive['opcache_enabled'] = false;
	$wp_perf_probe_opcache_inactive['jit']             = array( 'on' => false );

	$wp_perf_probe_opcache_on = array(
		'opcache.enable'          => true,
		'opcache.enable_cli'      => true,
		'opcache.jit'             => 'tracing',
		'opcache.jit_buffer_size' => 67108864,
	);

	$wp_perf_probe_opcache_off                   = $wp_perf_probe_opcache_on;
	$wp_perf_probe_opcache_off['opcache.enable'] = false;

	$wp_perf_probe_opcache_jit_off                = $wp_perf_probe_opcache_on;
	$wp_perf_probe_opcache_jit_off['opcache.jit'] = 'disable';

	$wp_perf_probe_opcache_no_buffer                            = $wp_perf_probe_opcache_on;
	$wp_perf_probe_opcache_no_buffer['opcache.jit_buffer_size'] = 0;

	$wp_perf_probe_metadata['opcache_configured_on'] = array_map(
		'wp_perf_probe_describe',
		wp_perf_opcache_metadata( $wp_perf_probe_opcache_status, $wp_perf_probe_opcache_on )
	);

	$wp_perf_probe_metadata['opcache_configured_off'] = array_map(
		'wp_perf_probe_describe',
		wp_perf_opcache_metadata( $wp_perf_probe_opcache_status, $wp_perf_probe_opcache_off )
	);

	$wp_perf_probe_metadata['opcache_accelerator_inactive'] = array_map(
		'wp_perf_probe_describe',
		wp_perf_opcache_metadata( $wp_perf_probe_opcache_inactive, $wp_perf_probe_opcache_on )
	);

	$wp_perf_probe_metadata['opcache_jit_off'] = array_map(
		'wp_perf_probe_describe',
		wp_perf_opcache_metadata( $wp_perf_probe_opcache_status, $wp_perf_probe_opcache_jit_off )
	);

	$wp_perf_probe_metadata['opcache_jit_without_buffer'] = array_map(
		'wp_perf_probe_describe',
		wp_perf_opcache_metadata( $wp_perf_probe_opcache_status, $wp_perf_probe_opcache_no_buffer )
	);

	$wp_perf_probe_metadata['opcache_unavailable'] = array_map(
		'wp_perf_probe_describe',
		wp_perf_opcache_metadata( false, $wp_perf_probe_opcache_off )
	);

	/*
	 * Directive resolution. A configuration snapshot wins whenever it carries the name, and
	 * an absent one delegates to ini_get() rather than reporting an unknown regime, because
	 * opcache.restrict_api refuses opcache_get_configuration() while never refusing
	 * ini_get(). Delegation is reported beside ini_get() read here in the same process, so
	 * asserting it needs no assumption about how this process is configured.
	 */
	$wp_perf_probe_metadata['opcache_directive'] = array_map(
		'wp_perf_probe_describe',
		array(
			'from_snapshot' => wp_perf_opcache_directive( array( 'opcache.enable' => 'off' ), 'opcache.enable' ),
			'from_ini'      => wp_perf_opcache_directive( null, 'opcache.enable' ),
			'ini_value'     => ini_get( 'opcache.enable' ),
			'name_absent'   => wp_perf_opcache_directive( array(), 'opcache.jit' ),
			'jit_ini_value' => ini_get( 'opcache.jit' ),
			'unknown_name'  => wp_perf_opcache_directive( null, 'opcache.not.a.real.directive' ),
		)
	);

	/*
	 * Boolean normalization. opcache_get_configuration() types a boolean directive as a
	 * boolean and ini_get() types it as a string whose spelling differs between builds, so
	 * every form either source can answer with is covered here.
	 */
	$wp_perf_probe_metadata['opcache_flag'] = array_map(
		'wp_perf_probe_describe',
		array(
			'boolean_true'  => wp_perf_opcache_flag( array( 'directive' => true ), 'directive' ),
			'boolean_false' => wp_perf_opcache_flag( array( 'directive' => false ), 'directive' ),
			'integer_one'   => wp_perf_opcache_flag( array( 'directive' => 1 ), 'directive' ),
			'integer_zero'  => wp_perf_opcache_flag( array( 'directive' => 0 ), 'directive' ),
			'float_one'     => wp_perf_opcache_flag( array( 'directive' => 1.0 ), 'directive' ),
			'float_zero'    => wp_perf_opcache_flag( array( 'directive' => 0.0 ), 'directive' ),
			'float_nan'     => wp_perf_opcache_flag( array( 'directive' => NAN ), 'directive' ),
			'string_one'    => wp_perf_opcache_flag( array( 'directive' => '1' ), 'directive' ),
			'string_on'     => wp_perf_opcache_flag( array( 'directive' => ' On ' ), 'directive' ),
			'string_true'   => wp_perf_opcache_flag( array( 'directive' => 'TRUE' ), 'directive' ),
			'string_yes'    => wp_perf_opcache_flag( array( 'directive' => 'yes' ), 'directive' ),
			'string_zero'   => wp_perf_opcache_flag( array( 'directive' => '0' ), 'directive' ),
			'string_off'    => wp_perf_opcache_flag( array( 'directive' => 'Off' ), 'directive' ),
			'string_empty'  => wp_perf_opcache_flag( array( 'directive' => '' ), 'directive' ),
			'array_value'   => wp_perf_opcache_flag( array( 'directive' => array( 1 ) ), 'directive' ),
			'null_value'    => wp_perf_opcache_flag( array( 'directive' => null ), 'directive' ),
		)
	);

	/*
	 * Only the four duration slugs are scaled to milliseconds. Every other slug carries a
	 * count, a flag or a byte size, so a value that is merely represented as a float must
	 * still pass through untouched: scaling one would multiply a byte total by 1000.
	 */
	$wp_perf_probe_metadata['header_values'] = array(
		'before_template' => wp_perf_server_timing_value( 'before-template', 0.01234 ),
		'template'        => wp_perf_server_timing_value( 'template', 0.01234 ),
		'total'           => wp_perf_server_timing_value( 'total', 0.01234 ),
		'bootstrap'       => wp_perf_server_timing_value( 'bootstrap', 0.01234 ),
		'rounds'          => wp_perf_server_timing_value( 'total', 0.0123456 ),
		'float_count'     => wp_perf_server_timing_value( 'files-loaded', 98.7654 ),
		'count'           => wp_perf_server_timing_value( 'files-loaded', 321 ),
		'bytes'           => wp_perf_server_timing_value( 'memory-peak', 7445592 ),
		'flag'            => wp_perf_server_timing_value( 'opcache-enabled', 1 ),
		'unknown'         => wp_perf_server_timing_value( 'not-a-metric', 0.01234 ),
	);
}

$wp_perf_probe_output = ob_get_clean();

$wp_perf_probe_report = array(
	'fixture'       => $argv[2],
	'hits'          => wp_perf_probe_describe( $wp_perf_probe_counters['hits'] ),
	'misses'        => wp_perf_probe_describe( $wp_perf_probe_counters['misses'] ),
	'keys'          => array_keys( $wp_perf_probe_counters ),
	'output_length' => strlen( $wp_perf_probe_output ),
	'diagnostics'   => $GLOBALS['wp_perf_probe_diagnostics'],
	'magic_calls'   => $GLOBALS['wp_perf_probe_magic_calls'],
	'registrations' => $GLOBALS['wp_perf_probe_registrations'],
	'bootstrap'     => $wp_perf_probe_bootstrap,
	'metadata'      => $wp_perf_probe_metadata,
	'functions'     => array_values(
		array_filter(
			get_defined_functions()['user'],
			static function ( $name ) {
				// This probe's own helpers share the prefix, so they are excluded by name.
				return 0 === strpos( $name, 'wp_perf_' ) && 0 !== strpos( $name, 'wp_perf_probe_' );
			}
		)
	),
);

echo wp_perf_probe_encode( $wp_perf_probe_report );
