/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { camelCaseDashes, clearServerCaches, themes, locales } from '../utils';

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
];

/**
 * Samples belonging to the theme and locale currently under measurement.
 *
 * Every required Server-Timing metric is declared here, derived from the list above
 * so the two cannot drift, because being declared is what gets a metric reset
 * between buckets. A metric only the ingestion loop creates would keep its samples
 * for the whole theme and locale matrix, and the median reported for a later bucket
 * would then be taken over measurements from the buckets before it.
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

/**
 * Highest iteration count this spec will generate measured tests for.
 *
 * The count is consumed while the module is evaluated, so it decides how many
 * Playwright tests exist rather than how one behaves. An unbounded value therefore
 * cannot be caught by an assertion inside a test: a non-finite count makes the
 * registration loop below run forever and an astronomically large one runs long
 * enough to be indistinguishable from a hang, so collection never finishes and the
 * check never gets to report anything. An explicit ceiling makes that outcome
 * impossible while leaving headroom over the run count
 * `tests/performance/playwright.config.js` defaults TEST_RUNS to.
 */
const maxIterations = 1000;

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
					/*
					 * Snapshot the payload and its sample counts before the cleanup below
					 * can empty them, so what gets validated is byte for byte what gets
					 * attached. The counts are read from the live keys, so a metric the
					 * ingestion loop created on the fly is reset and counted alongside the
					 * declared ones. Nothing may survive into the next theme or locale: a
					 * series that carries samples over hands the later bucket a median of
					 * measurements it never took.
					 */
					const body = JSON.stringify( results, null, 2 );
					const sampleCounts = Object.keys( results ).map(
						( metric ) => [ metric, results[ metric ].length ]
					);

					try {
						/*
						 * The check runs before the attachment, so the artifact can
						 * only ever receive a snapshot that has been validated, and a
						 * hook that ran after the cleanup below fails here rather than
						 * appending a zero-sample result object. compare-results.js
						 * rejects a run whose scenarios disagree about how many samples
						 * they hold, so one extra entry would invalidate the
						 * comparison. How many attachment hooks this spec has is
						 * enforced in specs/utils.test.js, which is the only end that
						 * can see more than one at a time.
						 */
						for ( const [ metric, samples ] of sampleCounts ) {
							expect(
								samples,
								`${ metric } should hold one sample per iteration for this theme and locale`
							).toBe( iterations );
						}

						await testInfo.attach( 'results', {
							body,
							contentType: 'application/json',
						} );
					} finally {
						/*
						 * Cleanup, so it runs whether or not the checks above passed. A
						 * failed check that skipped it would hand the next theme or
						 * locale both the samples this one measured and the language it
						 * was measured in, turning one reported failure into a run of
						 * meaningless numbers.
						 */
						for ( const metric of Object.keys( results ) ) {
							results[ metric ] = [];
						}

						await requestUtils.updateSiteSettings( {
							language: '',
						} );
					}
				} );

				for ( let i = 1; i <= iterations; i++ ) {
					test( `Measure load time metrics (${ i } of ${ iterations })`, async ( {
						page,
						metrics,
					} ) => {
						/*
						 * Every figure this spec reports is measured after a cache
						 * reset, so the reset is required rather than requested:
						 * clearServerCaches() fails the iteration unless the authorized
						 * reset handler answered 202.
						 */
						await clearServerCaches( page );

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

						for ( const [ key, value ] of Object.entries(
							serverTiming
						) ) {
							results[ camelCaseDashes( key ) ] ??= [];
							results[ camelCaseDashes( key ) ].push( value );
						}

						const ttfb = await metrics.getTimeToFirstByte();
						const lcp = await metrics.getLargestContentfulPaint();
						const lcpMinusTtfb = lcp - ttfb;

						/*
						 * Browser-side timings are read from separate performance entries,
						 * either of which can be absent on a navigation that did not paint
						 * or did not complete. An absent entry yields undefined, the
						 * subtraction below turns that into NaN, and JSON serialization
						 * turns both into null - at which point the sample is
						 * indistinguishable from a measurement and sorts as zero inside the
						 * median. The derived value is checked as well as its two sources,
						 * because a paint recorded before the response ended would produce a
						 * finite but negative interval.
						 */
						for ( const [ metric, value ] of [
							[ 'timeToFirstByte', ttfb ],
							[ 'largestContentfulPaint', lcp ],
							[ 'lcpMinusTtfb', lcpMinusTtfb ],
						] ) {
							expect(
								Number.isFinite( value ) && 0 <= value,
								`${ metric } should be measured as a finite, non-negative number, received ${ JSON.stringify(
									value
								) }`
							).toBe( true );
						}

						results.largestContentfulPaint.push( lcp );
						results.timeToFirstByte.push( ttfb );
						results.lcpMinusTtfb.push( lcpMinusTtfb );
					} );
				}
			} );
		}
	}
} );
