/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	camelCaseDashes,
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
 * Samples belonging to the locale currently under measurement.
 *
 * Every required Server-Timing metric is declared here, derived from the list above
 * so the two cannot drift, because being declared is what gets a metric reset
 * between locales. A metric that only the ingestion loop creates keeps its samples
 * across both locales, and that accumulation has been measured rather than assumed:
 * in one admin run the de_DE bucket held twelve 'wpMemoryUsage' samples for six
 * iterations, and the first three of each repetition were byte-identical to the
 * en_US ones, 6,745,720 and 6,746,360 against the locale's own 7,333,616. Its
 * reported median came out at 7,039,988, understating de_DE by 293,628 bytes, or
 * 4.0%. 'wpDbQueries' was undeclared too, so the figure this suite reports its
 * database-query target from was a median mixed across locales.
 *
 * The original five explicitly declared server metrics proved that the mixing they
 * prevent is real rather than hypothetical. In the same admin run, the metrics that
 * only the ingestion loop created showed it happening: the de_DE bucket held twelve
 * 'wpMemoryUsage' samples for six iterations, and the first three of each repetition
 * were byte-identical to the en_US ones, 6,745,720 and 6,746,360 against the locale's
 * own 7,333,616. Its reported median came out at 7,039,988, understating de_DE by
 * 293,628 bytes, or 4.0%. Every declared metric held exactly six. The runtime-regime
 * metadata and deterministic JavaScript byte totals follow the same reset contract.
 *
 * How wrong a mixed median can be is bounded by how far the locales really are
 * apart, and they are not close: 'wpMemoryPeak', which was already declared and
 * reset, measured a median of 7,305,568 bytes for en_US against 7,793,872 for
 * de_DE, a difference of 6.7%.
 *
 * The reset in `afterAll` reads these keys live rather than from a snapshot taken
 * here, so a metric that only starts arriving later is still reset and counted.
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

const immutableMeasurementMetrics = [
	'wpOpcacheEnabled',
	'wpOpcacheJit',
	'wpPhpVersionId',
	'adminJsRaw',
	'adminJsGzipped',
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

				for ( const metric of immutableMeasurementMetrics ) {
					expect(
						new Set( results[ metric ] ).size,
						`${ metric } must stay immutable within one measured locale`
					).toBe( 1 );
				}

				for ( const metric of Object.keys( results ) ) {
					results[ metric ] = [];
				}

				await requestUtils.updateSiteSettings( {
					language: '',
				} );

				/*
				 * Validated before it is attached, so the artifact can only ever
				 * receive a fully populated snapshot. A duplicate of this hook -
				 * the defect this ordering exists to catch - would run once the
				 * arrays above are already reset and would fail here instead of
				 * appending a zero-sample result object. Such an object is not
				 * inert: compare-results.js only pairs a scenario when the before
				 * and after result counts match, so one extra entry makes it
				 * suppress every paired value for this locale as N/A. Cardinality
				 * itself is covered in specs/utils.test.js, which is the only end
				 * that can see more than one attachment hook at a time.
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
			} );

			for ( let i = 1; i <= iterations; i++ ) {
				test( `Measure load time metrics (${ i } of ${ iterations })`, async ( {
					page,
					admin,
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
					const javaScriptResponses = [];
					const collectJavaScriptResponse = ( response ) => {
						const url = new URL( response.url() );

						if ( url.pathname.endsWith( '.js' ) ) {
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
					expect( serverTiming[ 'wp-process-id' ] ).toBeGreaterThan(
						0
					);
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
					results.timeToFirstByte.push( ttfb );

					/*
					 * Measured from the end of the response, so it excludes server time -
					 * the same definition metrics.getLoadingDurations() uses, read straight
					 * from the Navigation Timing API rather than through that helper. The
					 * helper also dereferences the 'first-paint' and 'first-contentful-paint'
					 * entries unconditionally, and an admin screen that has not painted by
					 * the time the load event fires records neither, so it throws
					 * "Cannot read properties of undefined (reading 'startTime')" and costs
					 * this spec a domContentLoaded sample over two paint metrics it does not
					 * report. Reading the navigation entry alone cannot fail that way: it is
					 * always present once navigation has completed.
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
