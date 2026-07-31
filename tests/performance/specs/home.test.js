/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { camelCaseDashes, themes, locales } from '../utils';

const results = {
	timeToFirstByte: [],
	largestContentfulPaint: [],
	lcpMinusTtfb: [],
	wpMemoryPeak: [],
	wpFilesLoaded: [],
	wpCacheHits: [],
	wpCacheMisses: [],
	wpBootstrap: [],
};

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
 * Metrics that belong to a single theme and locale and are reset after it.
 *
 * Read from the initializer above, so every declared metric is also the subject of
 * the sample-count check below: a declaration that loses its reset would start
 * accumulating across the theme and locale matrix and fail there. The Server-Timing
 * metrics that are not declared above are created on the fly by the ingestion loop
 * and keep accumulating, which is pre-existing behavior this harness leaves alone.
 */
const perDescribeMetrics = Object.keys( results );

test.describe( 'Homepage', () => {
	test.use( {
		storageState: {}, // User will be logged out.
	} );

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

					// Read before the resets below so the check runs after cleanup.
					const sampleCounts = perDescribeMetrics.map( ( metric ) => [
						metric,
						results[ metric ].length,
					] );

					results.largestContentfulPaint = [];
					results.timeToFirstByte = [];
					results.lcpMinusTtfb = [];
					results.wpMemoryPeak = [];
					results.wpFilesLoaded = [];
					results.wpCacheHits = [];
					results.wpCacheMisses = [];
					results.wpBootstrap = [];

					for ( const [ metric, samples ] of sampleCounts ) {
						expect(
							samples,
							`${ metric } should hold one sample per iteration for this theme and locale`
						).toBe( iterations );
					}
				} );

				const iterations = Number( process.env.TEST_RUNS );
				for ( let i = 1; i <= iterations; i++ ) {
					test( `Measure load time metrics (${ i } of ${ iterations })`, async ( {
						page,
						metrics,
					} ) => {
						// Unmeasured pre-navigation request, not the page under test. Caches and
						// OPcache are cleared only where the clear-cache.php mu-plugin is installed,
						// so the cache regime must be measured rather than assumed.
						await page.goto( '/?clear_cache' );

						// This is the actual page to test.
						await page.goto( '/' );

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

						results.largestContentfulPaint.push( lcp );
						results.timeToFirstByte.push( ttfb );
						results.lcpMinusTtfb.push( lcp - ttfb );
					} );
				}
			} );
		}
	}
} );
