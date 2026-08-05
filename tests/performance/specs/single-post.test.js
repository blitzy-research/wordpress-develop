/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { camelCaseDashes, themes, locales } from '../utils';

/**
 * Server-Timing entries every front-end iteration must report.
 *
 * A metric that silently stops being emitted leaves its array of samples short or
 * empty, and the median of an empty array is NaN, so the report would carry an
 * unusable figure instead of failing. Checking the raw entries here keeps that
 * class of defect inside the test run.
 */
const requiredServerTimingMetrics = [
	'wp-before-template',
	'wp-template',
	'wp-total',
	'wp-memory-usage',
	'wp-db-queries',
	'wp-ext-obj-cache',
	'wp-memory-peak',
	'wp-files-loaded',
	'wp-cache-hits',
	'wp-cache-misses',
	'wp-bootstrap',
	'wp-bootstrap-valid',
	'wp-opcache-enabled',
	'wp-opcache-jit',
	'wp-php-version-id',
	'wp-process-id',
	'wp-process-requests',
	'wp-opcache-cached-scripts',
	'wp-opcache-hit-rate',
];

/**
 * Samples belonging to the theme and locale currently under measurement.
 *
 * Every required Server-Timing metric is declared here, derived from the list above
 * so the two cannot drift, because being declared is what gets a metric reset
 * between buckets. A metric that only the ingestion loop creates keeps its samples
 * for the whole theme and locale matrix, and that accumulation has been measured
 * rather than assumed: in one admin run the de_DE bucket held twelve 'wpMemoryUsage'
 * samples for six iterations, the first three of each repetition byte-identical to
 * the en_US ones, and its reported median came out 4.0% below the locale's own
 * measurements. 'wpDbQueries' was one of the undeclared metrics, so the figure this
 * suite reports its database-query target from was a median mixed across every
 * theme and locale that had run before it.
 *
 * The reset in `afterAll` reads these keys live rather than from a snapshot taken
 * here, so a metric that only starts arriving later is still reset and counted.
 */
const results = {
	timeToFirstByte: [],
	largestContentfulPaint: [],
	lcpMinusTtfb: [],
	...Object.fromEntries(
		requiredServerTimingMetrics.map( ( metric ) => [
			camelCaseDashes( metric ),
			[],
		] )
	),
};

const immutableRuntimeMetrics = [
	'wpOpcacheEnabled',
	'wpOpcacheJit',
	'wpPhpVersionId',
];

/**
 * Highest iteration count this spec will generate measured tests for.
 *
 * The count is consumed while the module is evaluated, so it decides how many
 * Playwright tests exist rather than how one behaves. An unbounded value therefore
 * cannot be caught by an assertion inside a test: a non-finite count makes the
 * registration loop below run forever and an astronomically large one runs long
 * enough to be indistinguishable from a hang, so collection never finishes and the
 * check never gets to report anything. An explicit ceiling makes that outcome
 * impossible while leaving ample headroom over the 20 runs
 * `tests/performance/playwright.config.js` defaults TEST_RUNS to.
 */
const maxIterations = 1000;

// Read once at module scope so test generation and validation use the same count.
const iterations = Number( process.env.TEST_RUNS );

/**
 * Whether that count can actually be measured.
 *
 * Resolved here, synchronously, because the value has already done its damage by the
 * time any test runs. `Number.isSafeInteger()` rejects `NaN`, `Infinity` and integers
 * past 2^53 in one step, so an empty string, a zero, a negative, a fractional, a
 * non-numeric and a non-finite value all fail alongside a value that is merely
 * absurd, and the ceiling above rejects what remains.
 */
const hasMeasurableIterations =
	Number.isSafeInteger( iterations ) &&
	0 < iterations &&
	iterations <= maxIterations;

test.describe( 'Single Post', () => {
	test.use( {
		storageState: {}, // User will be logged out.
	} );

	test( 'measures at least one iteration per theme and locale', () => {
		expect(
			hasMeasurableIterations,
			`TEST_RUNS should be an integer between 1 and ${ maxIterations }, received ${ JSON.stringify(
				process.env.TEST_RUNS
			) }`
		).toBe( true );
	} );

	if ( ! hasMeasurableIterations ) {
		// Nothing measurable to register, and the check above already fails the run.
		return;
	}

	for ( const theme of themes ) {
		for ( const locale of locales ) {
			test.describe( `Theme: ${ theme }, Locale: ${ locale }`, () => {
				test.beforeAll( async ( { requestUtils } ) => {
					await requestUtils.activateTheme( theme );
					await requestUtils.updateSiteSettings( {
						language: 'en_US' === locale ? '' : locale,
					} );
				} );

				test.afterAll( async ( { requestUtils }, testInfo ) => {
					await testInfo.attach( 'results', {
						body: JSON.stringify( results, null, 2 ),
						contentType: 'application/json',
					} );

					await requestUtils.updateSiteSettings( {
						language: '',
					} );

					/*
					 * Read before the resets below so the check runs after cleanup, and read
					 * from the live keys so a metric the ingestion loop created on the fly is
					 * reset and counted alongside the declared ones. Nothing may survive into
					 * the next theme or locale: a series that carries samples over hands the
					 * later bucket a median of measurements it never took.
					 */
					const sampleCounts = Object.keys( results ).map( ( metric ) => [
						metric,
						results[ metric ].length,
					] );

					for ( const metric of immutableRuntimeMetrics ) {
						expect(
							new Set( results[ metric ] ).size,
							`${ metric } must stay immutable within one measured theme and locale`
						).toBe( 1 );
					}

					for ( const metric of Object.keys( results ) ) {
						results[ metric ] = [];
					}

					for ( const [ metric, samples ] of sampleCounts ) {
						expect(
							samples,
							`${ metric } should hold one sample per iteration for this theme and locale`
						).toBe( iterations );
					}
				} );

				for ( let i = 1; i <= iterations; i++ ) {
					test( `Measure load time metrics (${ i } of ${ iterations })`, async ( {
						page,
						metrics,
					} ) => {
						/*
						 * Unmeasured pre-navigation request, not the page under test.
						 *
						 * The clear-cache.php mu-plugin answers it with 202 and dies after
						 * resetting OPcache, APCu, the object cache and expired transients. Any
						 * other status means the mu-plugin is not installed and the request fell
						 * through to an ordinary page load, which resets nothing: the measured
						 * navigation below would then run against warm caches and a warm opcode
						 * cache while still being reported as uncached. Asserting the status is
						 * what makes the cache regime measured rather than assumed.
						 */
						const cacheReset = await page.goto( '/?clear_cache' );

						expect(
							cacheReset?.status(),
							'/?clear_cache should be answered by the clear-cache.php mu-plugin with HTTP 202, so the measured request is genuinely uncached'
						).toBe( 202 );

						// This is the actual page to test.
						await page.goto( '/2018/11/03/block-image/' );

						const serverTiming = await metrics.getServerTiming();

						for ( const metric of requiredServerTimingMetrics ) {
							const value = serverTiming[ metric ];

							expect(
								Number.isFinite( value ) && 0 <= value,
								`Server-Timing metric ${ metric } should be reported as a finite, non-negative number, received ${ JSON.stringify(
									value
								) }`
							).toBe( true );
						}

						/*
						 * 'wp-bootstrap' has a single boundary, from $timestart to 'wp_loaded'.
						 * A 0 flag means that boundary was never reached, so the accompanying
						 * duration is a placeholder rather than a measurement and must not be
						 * aggregated with the samples that are.
						 */
						expect(
							serverTiming[ 'wp-bootstrap-valid' ],
							'wp-bootstrap should be measured to its own wp_loaded boundary, so wp-bootstrap-valid should be 1'
						).toBe( 1 );
						expect( [ 0, 1 ] ).toContain(
							serverTiming[ 'wp-opcache-enabled' ]
						);
						expect( [ 0, 1 ] ).toContain(
							serverTiming[ 'wp-opcache-jit' ]
						);
						expect(
							serverTiming[ 'wp-php-version-id' ]
						).toBeGreaterThan( 0 );
						expect(
							serverTiming[ 'wp-process-id' ]
						).toBeGreaterThan( 0 );
						expect(
							serverTiming[ 'wp-process-requests' ]
						).toBeGreaterThan( 0 );
						expect(
							serverTiming[ 'wp-opcache-hit-rate' ]
						).toBeLessThanOrEqual( 100 );

						for ( const [ key, value ] of Object.entries(
							serverTiming
						) ) {
							results[ camelCaseDashes( key ) ] ??= [];
							results[ camelCaseDashes( key ) ].push( value );
						}

						const ttfb = await metrics.getTimeToFirstByte();
						const lcp = await metrics.getLargestContentfulPaint();

						results.largestContentfulPaint.push( lcp );
						results.timeToFirstByte.push( ttfb );
						results.lcpMinusTtfb.push( lcp - ttfb );
					} );
				}
			} );
		}
	}
} );
