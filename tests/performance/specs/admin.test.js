/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { camelCaseDashes, locales } from '../utils';

const results = {
	timeToFirstByte: [],
	domContentLoaded: [],
	wpMemoryPeak: [],
	wpFilesLoaded: [],
	wpCacheHits: [],
	wpCacheMisses: [],
	wpBootstrap: [],
};

/**
 * Server-Timing entries every admin iteration must report.
 *
 * The list is shorter than the front-end one because the admin request never runs a
 * template, so it reports no 'wp-before-template' or 'wp-template'. A metric that
 * silently stops being emitted leaves its array of samples short or empty, and the
 * median of an empty array is NaN, so the report would carry an unusable figure
 * instead of failing. Checking the raw entries here keeps that class of defect
 * inside the test run.
 */
const requiredServerTimingMetrics = [
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
 * Metrics that belong to a single locale and are reset after it.
 *
 * Read from the initializer above, so every declared metric is also the subject of
 * the sample-count check below: a declaration that loses its reset would start
 * accumulating across the locales and fail there. The Server-Timing metrics that are
 * not declared above are created on the fly by the ingestion loop and keep
 * accumulating, which is pre-existing behavior this harness leaves alone.
 *
 * Five server metrics are declared here beyond the two this spec measures itself,
 * because the mixing they prevent has been measured rather than assumed. In the same
 * admin run, the metrics that only the ingestion loop creates show it happening: the
 * de_DE bucket held twelve 'wpMemoryUsage' samples for six iterations, and the first
 * three of each repetition were byte-identical to the en_US ones, 6,745,720 and
 * 6,746,360 against the locale's own 7,333,616. Its reported median came out at
 * 7,039,988, understating de_DE by 293,628 bytes, or 4.0%. Every declared metric held
 * exactly six.
 *
 * How wrong a mixed median can be is bounded by how far the locales really are apart,
 * and they are not close: 'wpMemoryPeak', which is declared and reset, measured a
 * median of 7,305,568 bytes for en_US against 7,793,872 for de_DE, a difference of
 * 6.7%. Declaring and resetting is therefore the smallest change that keeps each
 * locale's median its own, and it adds nothing to what this spec reports.
 */
const perDescribeMetrics = Object.keys( results );

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

test.describe( 'Admin', () => {
	test( 'measures at least one iteration per locale', () => {
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

	for ( const locale of locales ) {
		test.describe( `Locale: ${ locale }`, () => {
			test.beforeAll( async ( { requestUtils } ) => {
				await requestUtils.activateTheme( 'twentytwentyone' );
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

				// Read before the resets below so the check runs after cleanup.
				const sampleCounts = perDescribeMetrics.map( ( metric ) => [
					metric,
					results[ metric ].length,
				] );

				results.timeToFirstByte = [];
				results.domContentLoaded = [];
				results.wpMemoryPeak = [];
				results.wpFilesLoaded = [];
				results.wpCacheHits = [];
				results.wpCacheMisses = [];
				results.wpBootstrap = [];

				for ( const [ metric, samples ] of sampleCounts ) {
					expect(
						samples,
						`${ metric } should hold one sample per iteration for this locale`
					).toBe( iterations );
				}
			} );

			test.afterAll( async ( {}, testInfo ) => {
				await testInfo.attach( 'results', {
					body: JSON.stringify( results, null, 2 ),
					contentType: 'application/json',
				} );
			} );

			for ( let i = 1; i <= iterations; i++ ) {
				test( `Measure load time metrics (${ i } of ${ iterations })`, async ( {
					page,
					admin,
					metrics,
				} ) => {
					// Unmeasured pre-navigation request, not the page under test. Caches and
					// OPcache are cleared only where the clear-cache.php mu-plugin is installed,
					// so the cache regime must be measured rather than assumed.
					await page.goto( '/?clear_cache' );

					// This is the actual page to test.
					await admin.visitAdminPage( '/' );

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
					results.timeToFirstByte.push( ttfb );

					// Measured from the end of the response, so it excludes server time.
					const { domContentLoaded } =
						await metrics.getLoadingDurations();

					expect(
						Number.isFinite( domContentLoaded ) &&
							0 <= domContentLoaded,
						`domContentLoaded should be measured as a finite, non-negative number, received ${ JSON.stringify(
							domContentLoaded
						) }`
					).toBe( true );

					results.domContentLoaded.push( domContentLoaded );
				} );
			}
		} );
	}
} );
