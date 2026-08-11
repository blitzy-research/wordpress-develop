/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	camelCaseDashes,
	clearServerCaches,
	getJavaScriptResponseByteSizes,
	locales,
} from '../utils';

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
	'wp-bootstrap',
];

/**
 * Server-Timing entries only an installation whose object cache keeps them reports.
 *
 * An `object-cache.php` drop-in replaces the class these counters are members of, and
 * the memcached drop-in the workflows install keeps neither, so the producer omits both
 * rather than publishing a zero that would read as a measured absence of cache traffic.
 * They are therefore required as a pair when the header carries them and not required at
 * all when it does not - which is a stronger contract than requiring them
 * unconditionally, because it is the one the installation can actually satisfy, and it
 * is what makes their absence visible instead of being reported as two zeroes.
 */
const conditionalServerTimingMetrics = [ 'wp-cache-hits', 'wp-cache-misses' ];

/**
 * Server-Timing entries whose zero could not have been measured.
 *
 * A request that reached the collector loaded files, allocated memory and took time, so
 * none of these can be zero, and a zero in one of them is a producer that stopped
 * measuring rather than an installation that got faster. Accepting any non-negative
 * number for them left that indistinguishable from a measurement: a metric forced to a
 * structural zero passed every check while its median went into the report as 0.
 *
 * The query count and the object cache flag are deliberately absent from this list. A
 * request served entirely from a cache can legitimately issue no query, and the flag is
 * 0 on every installation without a drop-in.
 */
const positiveServerTimingMetrics = [
	'wp-total',
	'wp-memory-usage',
	'wp-memory-peak',
	'wp-files-loaded',
	'wp-bootstrap',
];

/**
 * Samples belonging to the locale currently under measurement.
 *
 * Every required Server-Timing metric is declared here, derived from the list above
 * so the two cannot drift, because being declared is what gets a metric reset
 * between locales. A metric only the ingestion loop creates would keep its samples
 * across both locales, and the median reported for the second locale would then be
 * taken over measurements from both.
 *
 * The reset in `afterAll` reads these keys live rather than from a snapshot taken
 * here, so a metric that only starts arriving later is still reset and counted. The
 * deterministic JavaScript byte totals follow the same reset contract.
 */
const results = {
	timeToFirstByte: [],
	domContentLoaded: [],
	...Object.fromEntries(
		requiredServerTimingMetrics.map( ( metric ) => [
			camelCaseDashes( metric ),
			[],
		] )
	),
	adminJsRaw: [],
	adminJsGzipped: [],
};

const immutableMeasurementMetrics = [ 'adminJsRaw', 'adminJsGzipped' ];

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
				/*
				 * Snapshot the payload and its sample counts before the resets below can
				 * empty them, so what gets validated is byte for byte what gets attached.
				 * The counts are read from the live keys, so a metric the ingestion loop
				 * created on the fly is reset and counted alongside the declared ones.
				 * Nothing may survive into the next locale: a series that carries samples
				 * over hands the later bucket a median of measurements it never took.
				 */
				const body = JSON.stringify( results, null, 2 );
				const sampleCounts = Object.keys( results ).map( ( metric ) => [
					metric,
					results[ metric ].length,
				] );

				try {
					for ( const metric of immutableMeasurementMetrics ) {
						expect(
							new Set( results[ metric ] ).size,
							`${ metric } must stay immutable within one measured locale`
						).toBe( 1 );
					}

					/*
					 * Both checks run before the attachment, so the artifact can only
					 * ever receive a snapshot that has been validated, and a hook that
					 * ran after the cleanup below fails here rather than appending a
					 * zero-sample result object. compare-results.js rejects a run whose
					 * scenarios disagree about how many samples they hold, so one extra
					 * entry would invalidate the comparison. How many attachment hooks
					 * this spec has is enforced in specs/utils.test.js, which is the
					 * only end that can see more than one at a time.
					 */
					for ( const [ metric, samples ] of sampleCounts ) {
						expect(
							samples,
							`${ metric } should hold one sample per iteration for this locale`
						).toBe( iterations );
					}

					await testInfo.attach( 'results', {
						body,
						contentType: 'application/json',
					} );
				} finally {
					/*
					 * Cleanup, so it runs whether or not the checks above passed. A
					 * failed check that skipped it would hand the next locale both the
					 * samples this one measured and the language it was measured in,
					 * turning one reported failure into a run of meaningless numbers.
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
					admin,
					metrics,
				} ) => {
					/*
					 * Every figure this spec reports is measured after a cache reset, so
					 * the reset is required rather than requested: clearServerCaches()
					 * fails the iteration unless the authorized reset handler answered
					 * 202.
					 */
					await clearServerCaches( page );

					// This is the actual page to test.
					const javaScriptResponses = [];
					const collectJavaScriptResponse = ( response ) => {
						const url = new URL( response.url() );

						if ( url.pathname.endsWith( '.js' ) ) {
							javaScriptResponses.push( response );
							return;
						}

						/*
						 * The extension is not the only way an admin screen is served
						 * JavaScript: with CONCATENATE_SCRIPTS in effect the admin
						 * payload arrives from wp-admin/load-scripts.php, whose pathname
						 * ends in .php. Matching the declared type as well is what keeps
						 * this metric from silently under-reporting on such an install.
						 * The early return above is what keeps a response that satisfies
						 * both from being counted twice.
						 *
						 * The declared type alone is not enough to identify that payload,
						 * because admin-ajax.php answers with a JavaScript content type
						 * too: while the can_compress_scripts option has not been written
						 * yet, wp-admin/admin-footer.php runs compression_test(), and the
						 * three XHRs it fires would be counted as script the screen
						 * loaded. They would be counted on the first measured iteration
						 * only, because the last of them writes the option, so the metric
						 * would report one iteration larger than every other and the
						 * series would stop being a measurement of one code state.
						 * Requiring the browser to have fetched the response as a script
						 * is what keeps this counting the payload rather than anything
						 * that merely returns JavaScript. load-scripts.php is fetched as a
						 * script in both its compressed and uncompressed form, so nothing
						 * the branch exists for is lost.
						 */
						const contentType =
							response.headers()[ 'content-type' ] ?? '';

						if (
							'script' === response.request().resourceType() &&
							/\b(?:java|ecma)script\b/i.test( contentType )
						) {
							javaScriptResponses.push( response );
						}
					};

					page.on( 'response', collectJavaScriptResponse );
					try {
						await admin.visitAdminPage( '/' );
						await page.waitForLoadState( 'networkidle' );
					} finally {
						page.off( 'response', collectJavaScriptResponse );
					}

					const serverTiming = await metrics.getServerTiming();

					for ( const metric of requiredServerTimingMetrics ) {
						const value = serverTiming[ metric ];
						const positive =
							positiveServerTimingMetrics.includes( metric );

						expect(
							Number.isFinite( value ) &&
								( positive ? 0 < value : 0 <= value ),
							`Server-Timing metric ${ metric } should be reported as a finite, ${
								positive ? 'positive' : 'non-negative'
							} number, received ${ JSON.stringify( value ) }`
						).toBe( true );
					}

					/*
					 * Absence is a measurement here, so it is asserted rather than
					 * tolerated: the pair has to arrive together or not at all. A header
					 * carrying one of them is a producer that lost the other, which would
					 * otherwise reach the report as a series half as long as the rest.
					 */
					const publishedCacheMetrics =
						conditionalServerTimingMetrics.filter(
							( metric ) => undefined !== serverTiming[ metric ]
						);

					expect(
						0 === publishedCacheMetrics.length ||
							conditionalServerTimingMetrics.length ===
								publishedCacheMetrics.length,
						`The object cache counters should be reported as a pair or not at all, received ${ JSON.stringify(
							publishedCacheMetrics
						) }`
					).toBe( true );

					if ( publishedCacheMetrics.length ) {
						for ( const metric of conditionalServerTimingMetrics ) {
							const value = serverTiming[ metric ];

							expect(
								Number.isFinite( value ) && 0 <= value,
								`Server-Timing metric ${ metric } should be reported as a finite, non-negative number, received ${ JSON.stringify(
									value
								) }`
							).toBe( true );
						}

						/*
						 * Either counter can legitimately be 0 on its own - a persistent
						 * cache that answered everything has no misses - but a request
						 * that loaded WordPress read at least one option, so a cache
						 * reporting no lookups at all is a counter that stopped counting.
						 */
						expect(
							0 <
								serverTiming[ 'wp-cache-hits' ] +
									serverTiming[ 'wp-cache-misses' ],
							`A request that loaded WordPress should record at least one object cache lookup, received ${ serverTiming[ 'wp-cache-hits' ] } hits and ${ serverTiming[ 'wp-cache-misses' ] } misses`
						).toBe( true );
					}

					for ( const [ key, value ] of Object.entries(
						serverTiming
					) ) {
						results[ camelCaseDashes( key ) ] ??= [];
						results[ camelCaseDashes( key ) ].push( value );
					}

					const ttfb = await metrics.getTimeToFirstByte();

					/*
					 * Read from a performance entry that an incomplete navigation can leave
					 * absent, in which case the sample arrives as undefined and serializes
					 * to null, where it is indistinguishable from a measurement and sorts as
					 * zero inside the median.
					 */
					expect(
						Number.isFinite( ttfb ) && 0 <= ttfb,
						`timeToFirstByte should be measured as a finite, non-negative number, received ${ JSON.stringify(
							ttfb
						) }`
					).toBe( true );

					results.timeToFirstByte.push( ttfb );

					/*
					 * The interval from the navigation entry's responseEnd to its
					 * domContentLoadedEventEnd, so it excludes server time - the same
					 * definition metrics.getLoadingDurations() uses, read straight from
					 * the Navigation Timing API rather than through that helper. Reading
					 * the navigation entry directly keeps this sample independent of the
					 * paint entries that helper also dereferences, which an admin screen
					 * need not have recorded by the time the load event fires.
					 *
					 * This is a client-side interval, not the page level figure measured
					 * from navigationStart. The page level figure is not recorded as a
					 * second metric because it is already the sum of two metrics this
					 * spec records for the same navigation - timeToFirstByte plus this
					 * one - so adding it would publish a derived value as if it were an
					 * independent measurement.
					 */
					const domContentLoaded = await page.evaluate( () => {
						const [ navigation ] =
							performance.getEntriesByType( 'navigation' );

						return (
							navigation.domContentLoadedEventEnd -
							navigation.responseEnd
						);
					} );
					const javaScriptBytes =
						await getJavaScriptResponseByteSizes(
							javaScriptResponses
						);

					expect(
						Number.isFinite( domContentLoaded ) &&
							0 <= domContentLoaded,
						`domContentLoaded should be measured as a finite, non-negative number, received ${ JSON.stringify(
							domContentLoaded
						) }`
					).toBe( true );

					results.domContentLoaded.push( domContentLoaded );
					results.adminJsRaw.push( javaScriptBytes.raw );
					results.adminJsGzipped.push( javaScriptBytes.gzipped );

					expect( javaScriptBytes.raw ).toBeGreaterThan( 0 );
					expect( javaScriptBytes.gzipped ).toBeGreaterThan( 0 );
				} );
			}
		} );
	}
} );
