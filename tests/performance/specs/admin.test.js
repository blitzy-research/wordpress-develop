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
 * accumulating across the locales and fail there. The Server-Timing metrics are not
 * declared above; they are created on the fly by the ingestion loop and keep
 * accumulating, which is pre-existing behavior this harness leaves alone.
 */
const perDescribeMetrics = Object.keys( results );

test.describe( 'Admin', () => {
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

			const iterations = Number( process.env.TEST_RUNS );
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
