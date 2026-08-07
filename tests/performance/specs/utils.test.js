/**
 * External dependencies
 */
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';

/**
 * Internal dependencies
 */
import globalTeardown from '../config/global-teardown';
import PerformanceReporter from '../config/performance-reporter';
import performanceConfig from '../playwright.config';
import {
	CACHE_RESET_STATUS,
	CACHE_RESET_TOKEN_HEADER,
	CACHE_RESET_TOKEN_PATTERN,
	accumulateValues,
	camelCaseDashes,
	formatValue,
	getJavaScriptResponseByteSizes,
	invalidSeriesReason,
	isComparableMetric,
	median,
	validateResults,
} from '../utils';

/**
 * Source of the mu-plugin that produces the Server-Timing metrics.
 *
 * The metric vocabulary has three ends: the mu-plugin emits a slug, the specs ingest
 * the header name, and the report formats the resulting key. Deriving the first end
 * from the producer itself is what turns a mismatch between the three into a failing
 * test instead of a mislabelled figure in the report.
 */
const producerSource = readFileSync(
	join( __dirname, '..', 'wp-content', 'mu-plugins', 'server-timing.php' ),
	'utf8'
);

/**
 * Source of the helpers the specs reach the server through.
 *
 * The cache reset is a state-changing operation whose transport is a contract between
 * this side and the mu-plugin: the request has to be a POST and the secret has to travel
 * in a header. Neither property can be observed from a return value, and a regression in
 * either would surface as a warm sample published under an uncached label rather than as
 * a failure, so both are read off the source itself.
 */
const callerSource = readFileSync( join( __dirname, '..', 'utils.js' ), 'utf8' );

/**
 * Sources of the specs that attach measured results to the run.
 *
 * Cardinality cannot be observed from inside a spec: Playwright gives every
 * test.afterAll() hook its own TestInfo, so a hook cannot see an attachment made
 * by a sibling hook, and the reporter records only the first 'results' attachment
 * it finds on a test result. A duplicate hook is therefore silent until a test in
 * the same repetition fails, at which point the reset snapshot reaches the artifact
 * and compare-results.js rejects the whole run for holding a scenario whose sample
 * count disagrees with the rest. Reading the sources is what makes a second
 * attachment hook fail immediately instead.
 */
const attachingSpecs = [ 'admin', 'home', 'single-post' ].map( ( name ) => [
	`${ name }.test.js`,
	readFileSync( join( __dirname, `${ name }.test.js` ), 'utf8' ),
] );

/**
 * Source of the reporter that renders the before/after comparison table.
 *
 * Which columns a metric may fill is a property of the reporter rather than of any single
 * value, and the reporter cannot be called from a test: it reads the run artifacts, prints a
 * table and writes its own summary files. Reading its source is what keeps the exclusion
 * derived from the shared metric vocabulary instead of drifting back into a list of metric
 * names, which is how the flag and identifier metrics came to report a difference of
 * 'PHP 0.0.0' and 'PID -4'.
 */
const reporterSource = readFileSync(
	join( __dirname, '..', 'compare-results.js' ),
	'utf8'
);

/**
 * How every reported metric is expected to appear in the results table.
 *
 * 'count' means the raw number is passed through, which is the branch that carries
 * the query, file and cache-entry counts: losing it would render 484 loaded files as
 * '484.00 ms' without any test noticing.
 */
const reportedAs = {
	wpBeforeTemplate: 'ms',
	wpTemplate: 'ms',
	wpTotal: 'ms',
	wpMemoryUsage: 'MB',
	wpDbQueries: 'count',
	wpExtObjCache: 'flag',
	wpMemoryPeak: 'MB',
	wpFilesLoaded: 'count',
	wpCacheHits: 'count',
	wpCacheMisses: 'count',
	wpBootstrap: 'ms',
	wpBootstrapValid: 'count',
	wpOpcacheEnabled: 'flag',
	wpOpcacheJit: 'flag',
};

/**
 * Reads the metric slugs the mu-plugin reports for one of its request contexts.
 *
 * @param {'front-end'|'admin'} context Request context to read.
 * @return {string[]} Slugs in the order the producer assigns them.
 */
function producerSlugs( context ) {
	const adminAt = producerSource.indexOf( "'admin_init'" );
	const section =
		'admin' === context
			? producerSource.slice( adminAt )
			: producerSource.slice( 0, adminAt );

	const slugs = [];
	for ( const [ , slug ] of section.matchAll(
		/\$server_timing_values\[\s*'([a-z-]+)'\s*\]\s*=/g
	) ) {
		if ( ! slugs.includes( slug ) ) {
			slugs.push( slug );
		}
	}

	return slugs;
}

/**
 * Converts a producer slug into the key the report stores it under.
 *
 * @param {string} slug Producer slug, without the 'wp-' prefix the header adds.
 * @return {string} Report key.
 */
function reportKeyForSlug( slug ) {
	return camelCaseDashes( `wp-${ slug }` );
}

/**
 * Classifies how a metric is rendered, without restating the formatting itself.
 *
 * @param {string} metric Report key.
 * @return {string} Expected unit name, or the unexpected formatted output.
 */
function reportedUnit( metric ) {
	const formatted = formatValue( metric, 1 );

	if ( 'number' === typeof formatted ) {
		return 'count';
	}

	if ( 'yes' === formatted || 'no' === formatted ) {
		return 'flag';
	}

	if ( formatted.endsWith( ' MB' ) ) {
		return 'MB';
	}

	if ( formatted.endsWith( ' ms' ) ) {
		return 'ms';
	}

	return formatted;
}

test.describe( 'Performance report utilities', () => {
	test.describe( 'camelCaseDashes()', () => {
		test( 'converts every Server-Timing header name into its report key', () => {
			const headerNames = [
				[ 'wp-before-template', 'wpBeforeTemplate' ],
				[ 'wp-template', 'wpTemplate' ],
				[ 'wp-total', 'wpTotal' ],
				[ 'wp-memory-usage', 'wpMemoryUsage' ],
				[ 'wp-db-queries', 'wpDbQueries' ],
				[ 'wp-ext-obj-cache', 'wpExtObjCache' ],
				[ 'wp-memory-peak', 'wpMemoryPeak' ],
				[ 'wp-files-loaded', 'wpFilesLoaded' ],
				[ 'wp-cache-hits', 'wpCacheHits' ],
				[ 'wp-cache-misses', 'wpCacheMisses' ],
				[ 'wp-bootstrap', 'wpBootstrap' ],
				[ 'wp-opcache-enabled', 'wpOpcacheEnabled' ],
				[ 'wp-opcache-jit', 'wpOpcacheJit' ],
				[ 'wp-bootstrap-valid', 'wpBootstrapValid' ],
			];

			for ( const [ headerName, reportKey ] of headerNames ) {
				expect( camelCaseDashes( headerName ), headerName ).toBe(
					reportKey
				);
			}
		} );

		test( 'leaves names without a lowercase letter after a dash alone', () => {
			expect( camelCaseDashes( 'wpTotal' ) ).toBe( 'wpTotal' );
			expect( camelCaseDashes( 'total' ) ).toBe( 'total' );
			expect( camelCaseDashes( 'wp-' ) ).toBe( 'wp-' );
			expect( camelCaseDashes( 'wp-2' ) ).toBe( 'wp-2' );
			expect( camelCaseDashes( '' ) ).toBe( '' );
		} );
	} );

	test.describe( 'formatValue()', () => {
		test( 'renders each metric in its own unit', () => {
			const expected = [
				[ 'wpMemoryUsage', 5550000, '5.55 MB' ],
				[ 'wpMemoryPeak', 4284512, '4.28 MB' ],
				[ 'wpMemoryPeak', 0, '0.00 MB' ],
				[ 'wpExtObjCache', 1, 'yes' ],
				[ 'wpExtObjCache', 0, 'no' ],
				[ 'wpDbQueries', 25, 25 ],
				[ 'wpFilesLoaded', 484, 484 ],
				[ 'wpCacheHits', 2035, 2035 ],
				[ 'wpCacheMisses', 185, 185 ],
				[ 'wpBootstrap', 23.897, '23.90 ms' ],
				[ 'wpOpcacheEnabled', 1, 'yes' ],
				[ 'wpOpcacheEnabled', 0, 'no' ],
				[ 'wpOpcacheJit', 1, 'yes' ],
				[ 'wpOpcacheJit', 0, 'no' ],
				[ 'adminJsRaw', 124500, '124.50 kB' ],
				[ 'adminJsGzipped', 30250, '30.25 kB' ],
				[ 'wpBootstrapValid', 1, 1 ],
				[ 'wpBootstrapValid', 0, 0 ],
				[ 'wpTotal', 39.75, '39.75 ms' ],
				[ 'wpBeforeTemplate', 29.14, '29.14 ms' ],
				[ 'wpTemplate', 32.876, '32.88 ms' ],
				[ 'timeToFirstByte', 64.6, '64.60 ms' ],
				[ 'domContentLoaded', 612, '612.00 ms' ],
				[ 'largestContentfulPaint', 114, '114.00 ms' ],
				[ 'lcpMinusTtfb', 48.85, '48.85 ms' ],
			];

			for ( const [ metric, value, formatted ] of expected ) {
				expect( formatValue( metric, value ), metric ).toBe(
					formatted
				);
			}
		} );

		test( 'passes counts through as numbers rather than durations', () => {
			for ( const [ metric, unit ] of Object.entries( reportedAs ) ) {
				if ( 'count' !== unit ) {
					continue;
				}

				expect( typeof formatValue( metric, 484 ), metric ).toBe(
					'number'
				);
				expect( formatValue( metric, 484 ), metric ).toBe( 484 );
			}
		} );

		test( 'reports a missing measurement as not available', () => {
			expect( formatValue( 'wpMemoryPeak', null ) ).toBe( 'N/A' );
			expect( formatValue( 'wpFilesLoaded', null ) ).toBe( 'N/A' );
			expect( formatValue( 'wpExtObjCache', null ) ).toBe( 'N/A' );
			expect( formatValue( 'wpBootstrap', null ) ).toBe( 'N/A' );
			expect( formatValue( 'wpOpcacheEnabled', null ) ).toBe( 'N/A' );
			expect( formatValue( 'wpCacheHits', null ) ).toBe( 'N/A' );
			expect( formatValue( 'adminJsGzipped', null ) ).toBe( 'N/A' );
		} );
	} );

	test.describe( 'isComparableMetric()', () => {
		test( 'refuses to compare flags', () => {
			/*
			 * Every one of these formats into a label. Differencing them produced the cells
			 * the classification exists to prevent, such as 'no' for a flag that never
			 * changed.
			 */
			for ( const metric of [
				'wpExtObjCache',
				'wpOpcacheEnabled',
				'wpOpcacheJit',
			] ) {
				expect( isComparableMetric( metric ), metric ).toBe( false );
			}
		} );

		test( 'refuses to compare a validity flag', () => {
			/*
			 * 'wpBootstrapValid' states whether the bootstrap duration beside it was
			 * measured at all. It is formatted as a raw number, because a run in which it
			 * is not 1 is a run whose bootstrap figures must be discarded, but a
			 * difference of -1 between two runs reads as a one-unit regression in a
			 * metric that has no units.
			 */
			expect( reportedUnit( 'wpBootstrapValid' ) ).toBe( 'count' );
			expect( isComparableMetric( 'wpBootstrapValid' ) ).toBe( false );
		} );

		test( 'compares every metric that carries a quantity', () => {
			const labelUnits = [ 'flag' ];
			const validityMetrics = [ 'wpBootstrapValid' ];

			for ( const [ metric, unit ] of Object.entries( reportedAs ) ) {
				expect( isComparableMetric( metric ), metric ).toBe(
					! labelUnits.includes( unit ) &&
						! validityMetrics.includes( metric )
				);
			}

			// The metrics the specs measure in the browser are quantities as well.
			for ( const metric of [
				'timeToFirstByte',
				'domContentLoaded',
				'largestContentfulPaint',
				'lcpMinusTtfb',
				'adminJsRaw',
				'adminJsGzipped',
			] ) {
				expect( isComparableMetric( metric ), metric ).toBe( true );
			}
		} );

		test( 'the reporter derives its difference columns from this classification', () => {
			expect(
				reporterSource,
				'compare-results.js should ask whether the metric is comparable'
			).toMatch( /isComparableMetric\(\s*metric\s*\)/ );

			expect(
				reporterSource,
				'the difference columns must not be excluded one metric name at a time'
			).not.toMatch( /metric\s*!==\s*'/ );
		} );
	} );

	test.describe( 'getJavaScriptResponseByteSizes()', () => {
		test( 'sums raw bytes and gzip level 9 bytes per response', async () => {
			const responses = [
				{ body: async () => Buffer.from( 'hello' ) },
				{ body: async () => Buffer.from( 'hello' ) },
			];

			await expect(
				getJavaScriptResponseByteSizes( responses )
			).resolves.toEqual( {
				raw: 10,
				gzipped: 50,
			} );
		} );

		test( 'reports zero bytes when no JavaScript responses were loaded', async () => {
			await expect(
				getJavaScriptResponseByteSizes( [] )
			).resolves.toEqual( {
				raw: 0,
				gzipped: 0,
			} );
		} );
	} );

	test.describe( 'metric vocabulary', () => {
		test( 'the producer, the header names and the report agree', () => {
			const frontEndKeys =
				producerSlugs( 'front-end' ).map( reportKeyForSlug );

			expect( frontEndKeys ).toEqual( Object.keys( reportedAs ) );

			for ( const metric of frontEndKeys ) {
				expect( reportedUnit( metric ), metric ).toBe(
					reportedAs[ metric ]
				);
			}
		} );

		test( 'the admin context reports the same metrics minus the template ones', () => {
			const adminKeys = producerSlugs( 'admin' ).map( reportKeyForSlug );

			expect( adminKeys ).toEqual(
				Object.keys( reportedAs ).filter(
					( metric ) =>
						! [ 'wpBeforeTemplate', 'wpTemplate' ].includes(
							metric
						)
				)
			);
		} );
	} );

	test.describe( 'result cardinality', () => {
		test( 'every measured scenario attaches exactly one result object', () => {
			for ( const [ name, source ] of attachingSpecs ) {
				expect(
					[ ...source.matchAll( /attach\(\s*'results'/g ) ].length,
					`${ name } should attach results once per describe block`
				).toBe( 1 );

				expect(
					[ ...source.matchAll( /test\.afterAll\(/g ) ].length,
					`${ name } should register one result attachment hook`
				).toBe( 1 );
			}
		} );

		test( 'every attached snapshot is validated against the iteration count', () => {
			for ( const [ name, source ] of attachingSpecs ) {
				expect(
					source,
					`${ name } should assert one sample per iteration before reporting`
				).toContain( 'should hold one sample per iteration' );

				expect(
					source,
					`${ name } should compare the sample count to the iteration count`
				).toContain( '.toBe( iterations )' );
			}
		} );

		test( 'every spec validates, then attaches, then cleans up', () => {
			/*
			 * A hook that attaches first publishes whatever it happened to collect and
			 * only then discovers the collection was wrong, and a hook that empties the
			 * live series first lets a duplicate of itself attach a zero-sample result
			 * object instead of failing. Validating before attaching makes both
			 * impossible. Cleaning up afterwards in a `finally` covers the third case:
			 * a failed check that skipped the reset would hand the next scenario this
			 * one's samples and its locale, so one reported failure would become a run
			 * of numbers that describe nothing.
			 */
			for ( const [ name, source ] of attachingSpecs ) {
				const validated = source.indexOf( '.toBe( iterations )' );
				const attached = source.indexOf( "attach( 'results'" );
				const cleanedUp = source.indexOf( 'results[ metric ] = [];' );

				expect(
					validated,
					`${ name } should validate the sample count before attaching it`
				).toBeLessThan( attached );

				expect(
					cleanedUp,
					`${ name } should reset the live series after attaching`
				).toBeGreaterThan( attached );

				expect(
					source.indexOf( '} finally {' ),
					`${ name } should reset the live series from a finally block`
				).toBeLessThan( cleanedUp );

				expect(
					source.slice( attached ),
					`${ name } should restore the default language during cleanup`
				).toContain( 'updateSiteSettings' );
			}
		} );
	} );

	test.describe( 'unusable samples', () => {
		test( 'a series with nothing to median is rejected rather than medianed', () => {
			/*
			 * median( [] ) returned NaN, which formatted into a report cell as 'NaN ms'
			 * for a duration and as NaN for a count. Both reached the results table
			 * looking like a figure, so a metric that stopped being emitted was reported
			 * rather than noticed.
			 */
			expect( () => median( [] ) ).toThrow( /no samples/ );
		} );

		test( 'a series holding a serialized gap is rejected rather than sorted', () => {
			/*
			 * Attachments are serialized as JSON, where both undefined and NaN become
			 * null, and null sorts as though it were zero. A single missing browser timing
			 * therefore pulled the median of an otherwise valid run towards zero.
			 */
			expect(
				JSON.parse(
					JSON.stringify( { wpFilesLoaded: [ undefined, NaN ] } )
				)
			).toEqual( { wpFilesLoaded: [ null, null ] } );

			expect( () => median( [ 484, null, 486 ] ) ).toThrow(
				/not a finite number/
			);
			expect( () => median( [ 484, NaN ] ) ).toThrow(
				/not a finite number/
			);
			expect( () => median( [ 484, '485' ] ) ).toThrow(
				/not a finite number/
			);
			expect( () => median( [ Infinity ] ) ).toThrow(
				/not a finite number/
			);
		} );

		test( 'a usable series is still medianed', () => {
			expect( median( [ 3, 1, 2 ] ) ).toBe( 2 );
			expect( median( [ 4, 1, 3, 2 ] ) ).toBe( 2.5 );
			expect( median( [ 0 ] ) ).toBe( 0 );
			expect( median( [ -2, -1 ] ) ).toBe( -1.5 );
			expect( invalidSeriesReason( [ 0, -1.5 ] ) ).toBeNull();
		} );

		test( 'a repetition that is missing a metric is rejected rather than shortened', () => {
			/*
			 * Accumulating silently left 'wpFilesLoaded' with one sample where every
			 * sibling metric had two, so its median was taken over a different iteration
			 * count than the row beside it.
			 */
			expect( () =>
				accumulateValues( [ { wpFilesLoaded: [ 484 ] }, {} ] )
			).toThrow( /reports no metrics/ );

			expect( () =>
				accumulateValues( [
					{ wpFilesLoaded: [ 484 ], wpDbQueries: [ 24 ] },
					{ wpFilesLoaded: [ 486 ] },
				] )
			).toThrow( /repetition 0 reports/ );

			expect( () => accumulateValues( [] ) ).toThrow(
				/holds no repetitions/
			);
			expect( () =>
				accumulateValues( [ { wpFilesLoaded: [ 484, null ] } ] )
			).toThrow( /not a finite number/ );
		} );

		test( 'a complete scenario still accumulates every repetition', () => {
			expect(
				accumulateValues( [
					{ wpFilesLoaded: [ 484, 485 ], wpDbQueries: [ 24, 24 ] },
					{ wpFilesLoaded: [ 486, 487 ], wpDbQueries: [ 25, 25 ] },
				] )
			).toEqual( {
				wpFilesLoaded: [ 484, 485, 486, 487 ],
				wpDbQueries: [ 24, 24, 25, 25 ],
			} );
		} );
	} );

	test.describe( 'artifact validation', () => {
		const scenario = ( overrides = {} ) => ( {
			file: 'tests/performance/specs/home.test.js',
			title: 'Theme: twentytwentyfive, Locale: en_US › Measure load time metrics',
			results: [
				{ wpFilesLoaded: [ 484, 485 ], wpDbQueries: [ 24, 24 ] },
				{ wpFilesLoaded: [ 486, 487 ], wpDbQueries: [ 25, 25 ] },
			],
			...overrides,
		} );

		test( 'accepts an artifact the comparison can read positionally', () => {
			const stats = [ scenario() ];

			expect( validateResults( stats, 'performance-results.json' ) ).toBe(
				stats
			);
		} );

		test( 'rejects an artifact that is not an array of scenarios', () => {
			for ( const malformed of [ {}, 'results', 7, null, true ] ) {
				expect(
					() => validateResults( malformed, 'artifact.json' ),
					JSON.stringify( malformed )
				).toThrow( /expected an array of scenarios/ );
			}
		} );

		test( 'rejects an artifact with no scenario to compare', () => {
			expect( () => validateResults( [], 'artifact.json' ) ).toThrow(
				/holds no scenarios/
			);
		} );

		test( 'rejects a scenario the comparison could not match', () => {
			expect( () =>
				validateResults( [ scenario( { title: '' } ) ], 'artifact.json' )
			).toThrow( /has no title/ );

			expect( () =>
				validateResults(
					[ scenario( { title: 7 } ) ],
					'artifact.json'
				)
			).toThrow( /has no title/ );

			expect( () =>
				validateResults( [ null ], 'artifact.json' )
			).toThrow( /is not an object/ );
		} );

		test( 'rejects a duplicated scenario, whose earlier results would be discarded', () => {
			expect( () =>
				validateResults( [ scenario(), scenario() ], 'artifact.json' )
			).toThrow( /appears more than once/ );
		} );

		test( 'rejects a scenario that measured nothing', () => {
			expect( () =>
				validateResults(
					[ scenario( { results: [] } ) ],
					'artifact.json'
				)
			).toThrow( /holds no repetitions/ );

			expect( () =>
				validateResults(
					[ scenario( { results: {} } ) ],
					'artifact.json'
				)
			).toThrow( /holds no repetitions/ );

			expect( () =>
				validateResults(
					[ scenario( { results: [ {} ] } ) ],
					'artifact.json'
				)
			).toThrow( /reports no metrics/ );
		} );

		test( 'rejects a metric present in one repetition and missing from another', () => {
			expect( () =>
				validateResults(
					[
						scenario( {
							results: [
								{
									wpFilesLoaded: [ 484, 485 ],
									wpDbQueries: [ 24, 24 ],
								},
								{ wpFilesLoaded: [ 486, 487 ] },
							],
						} ),
					],
					'artifact.json'
				)
			).toThrow( /repetition 1 reports/ );
		} );

		test( 'rejects repetitions measured a different number of times', () => {
			expect( () =>
				validateResults(
					[
						scenario( {
							results: [
								{ wpFilesLoaded: [ 484, 485 ] },
								{ wpFilesLoaded: [ 486 ] },
							],
						} ),
					],
					'artifact.json'
				)
			).toThrow( /holds 1 samples where 2 were measured/ );
		} );

		test( 'rejects a series that cannot be medianed', () => {
			for ( const [ samples, pattern ] of [
				[ [], /holds no samples/ ],
				[ [ 484, null ], /not a finite number/ ],
				[ [ 484, NaN ], /not a finite number/ ],
				[ '484', /expected an array of samples/ ],
			] ) {
				expect( () =>
					validateResults(
						[
							scenario( {
								results: [ { wpFilesLoaded: samples } ],
							} ),
						],
						'artifact.json'
					),
					JSON.stringify( samples )
				).toThrow( pattern );
			}
		} );

		test( 'names the artifact, the scenario, the repetition and the metric', () => {
			expect( () =>
				validateResults(
					[
						scenario( {
							results: [ { wpFilesLoaded: [ 484, null ] } ],
						} ),
					],
					'performance-results.json'
				)
			).toThrow(
				/^performance-results\.json: scenario '.+' repetition 0 metric 'wpFilesLoaded' sample 1 is null/
			);
		} );
	} );

	test.describe( 'uncached measurement contract', () => {
		test( 'the producer answers the reset request with the status the specs require', () => {
			expect( CACHE_RESET_STATUS ).toBe( 202 );

			expect(
				producerSource,
				'server-timing.php should answer ?clear_cache itself, because the CI workflow copies only that file into the installed tree'
			).toMatch( /\$_GET\[\s*'clear_cache'\s*\]/ );

			expect(
				producerSource,
				'the reset must discard the opcode cache, the object cache and the expired transients'
			).toMatch( /opcache_reset\(\)/ );
			expect( producerSource ).toMatch( /wp_cache_flush\(\)/ );
			expect( producerSource ).toMatch(
				/delete_expired_transients\(\s*true\s*\)/
			);

			expect(
				producerSource,
				`the reset must answer ${ CACHE_RESET_STATUS }, which WordPress never sends for that URL`
			).toMatch(
				new RegExp( `status_header\\(\\s*${ CACHE_RESET_STATUS }\\s*\\)` )
			);

			/*
			 * The reset discards the opcode cache, the object cache and the expired
			 * transients for the whole installation, so the query argument may only
			 * select the endpoint. Authorization is a secret in a request header,
			 * presented on a POST, and every other shape has to be refused. Losing any
			 * of these would leave a reachable, unauthenticated way to force a cold
			 * cache on whatever host the harness is installed on.
			 */
			expect(
				producerSource,
				'the reset must accept POST only, so it cannot be reached by a navigation, a prefetch or an embedded resource'
			).toContain( "'POST' !== strtoupper( $method )" );

			expect(
				producerSource,
				'the presented secret must be compared with hash_equals(), so the comparison is not a timing oracle'
			).toContain( 'hash_equals( $token, $presented )' );

			expect(
				producerSource,
				`the secret must be read from the ${ CACHE_RESET_TOKEN_HEADER } request header, which a cross-origin form cannot set`
			).toContain(
				`$_SERVER['HTTP_${ CACHE_RESET_TOKEN_HEADER.toUpperCase().replaceAll(
					'-',
					'_'
				) }']`
			);

			expect(
				producerSource,
				'the secret grammar must be the one this side writes, or a provisioned token would leave the endpoint disabled'
			).toContain( CACHE_RESET_TOKEN_PATTERN.source );

			for ( const refusal of [ 404, 405, 403 ] ) {
				expect(
					producerSource,
					`the control plane must fail closed with ${ refusal } rather than fall through to the reset`
				).toMatch( new RegExp( `\\breturn ${ refusal };` ) );
			}
		} );

		test( 'every measured navigation is preceded by an asserted reset', () => {
			for ( const [ name, source ] of attachingSpecs ) {
				expect(
					source,
					`${ name } should reset the caches through clearServerCaches(), which fails unless the helper answered ${ CACHE_RESET_STATUS }`
				).toContain( 'await clearServerCaches( page )' );

				expect(
					source,
					`${ name } should not request /?clear_cache without checking the response`
				).not.toMatch( /goto\(\s*'\/\?clear_cache'\s*\)/ );
			}

			/*
			 * The reset is requested rather than navigated to, and the secret is carried
			 * in a header rather than in the URL: a secret in an address is sent by
			 * anything that copies it and is recorded in the access log, the referrer and
			 * the browser history.
			 */
			expect(
				callerSource,
				'clearServerCaches() should POST the reset through the request API rather than navigate to it'
			).toContain( "page.request.post( '/?clear_cache', {" );

			expect(
				callerSource,
				'clearServerCaches() should present the secret in the reset token header'
			).toContain( '[ CACHE_RESET_TOKEN_HEADER ]: cacheResetToken()' );

			expect(
				callerSource,
				'clearServerCaches() should not navigate to the reset URL'
			).not.toMatch( /goto\(\s*'\/\?clear_cache'/ );

			expect(
				callerSource,
				'no secret may be interpolated into the reset URL'
			).not.toMatch( /clear_cache=\$\{/ );

			/*
			 * The suite's logs and its artifacts are uploaded wholesale by the
			 * performance workflow, so a diagnostic that quoted the secret would publish
			 * it. Every failure message names the path instead of the value.
			 */
			expect(
				callerSource,
				'no diagnostic may quote the secret; report the path it was read from instead'
			).not.toMatch( /\$\{\s*cacheResetToken\(\)\s*\}/ );
		} );

		test( 'the regime each sample was taken in is reported with it', () => {
			for ( const [ name, source ] of attachingSpecs ) {
				for ( const metric of [
					'wp-opcache-enabled',
					'wp-opcache-jit',
				] ) {
					expect(
						source,
						`${ name } should report ${ metric } alongside every measurement`
					).toContain( metric );
				}
			}
		} );
	} );
} );

test.describe( 'Performance comparison contract', () => {
	/**
	 * Builds one measured scenario.
	 *
	 * @param {Object}                     overrides Metric series to use instead of the defaults.
	 * @param {number}                     samples   Samples per repetition.
	 * @return {{file: string, title: string, results: Record<string, number[]>[]}} Scenario.
	 */
	function scenario( overrides = {}, samples = 2 ) {
		const series = ( value ) => new Array( samples ).fill( value );

		return {
			file: 'tests/performance/specs/home.test.js',
			title: 'Homepage › Theme: twentytwentyone, Locale: en_US',
			results: [
				{
					wpFilesLoaded: series( 500 ),
					wpOpcacheEnabled: series( 1 ),
					wpOpcacheJit: series( 0 ),
					...overrides,
				},
			],
		};
	}

	/**
	 * Runs the comparison against a fixture pair.
	 *
	 * @param {?Array<Object>} before Baseline results, or null to leave the file out.
	 * @param {?Array<Object>} after  Measured results, or null to leave the file out.
	 * @param {Object}         env    Extra environment variables.
	 * @return {{status: ?number, stdout: string, stderr: string}} Result of the run.
	 */
	function compare( before, after, env = {} ) {
		const artifacts = mkdtempSync(
			join( tmpdir(), 'wp-performance-compare-' )
		);

		if ( null !== before ) {
			writeFileSync(
				join( artifacts, 'before-performance-results.json' ),
				JSON.stringify( before )
			);
		}

		if ( null !== after ) {
			writeFileSync(
				join( artifacts, 'performance-results.json' ),
				JSON.stringify( after )
			);
		}

		const run = spawnSync(
			process.execPath,
			[ join( __dirname, '..', 'compare-results.js' ) ],
			{
				encoding: 'utf8',
				env: {
					...process.env,
					WP_ARTIFACTS_PATH: artifacts,
					PERFORMANCE_ALLOW_MISSING_BASELINE: '',
					TARGET_SHA: '',
					GITHUB_SHA: '',
					...env,
				},
			}
		);

		return {
			status: run.status,
			stdout: run.stdout ?? '',
			stderr: run.stderr ?? '',
			artifacts,
		};
	}

	test( 'reports a reduction relative to the before value', () => {
		const run = compare(
			[ scenario() ],
			[ scenario( { wpFilesLoaded: [ 383, 383 ] } ) ]
		);

		expect( run.stderr ).toBe( '' );
		expect( run.status ).toBe( 0 );
		/*
		 * 500 to 383 is a 23.40% reduction. Dividing by the after value instead reports
		 * -30.55%, which turns a missed 30% target into a met one.
		 */
		expect( run.stdout ).toContain( '-23.40 %' );
		expect( run.stdout ).not.toContain( '-30.55 %' );
	} );

	test( 'refuses to run without measured results', () => {
		const run = compare( [ scenario() ], null );

		expect( run.status ).toBe( 1 );
		expect( run.stderr ).toContain( 'performance-results.json is missing' );
	} );

	test( 'refuses to report a comparison without a baseline', () => {
		const run = compare( null, [ scenario() ] );

		expect( run.status ).toBe( 1 );
		expect( run.stderr ).toContain(
			'before-performance-results.json is missing'
		);
	} );

	test( 'says so plainly when a baseline is deliberately absent', () => {
		const run = compare( null, [ scenario() ], {
			PERFORMANCE_ALLOW_MISSING_BASELINE: '1',
		} );

		expect( run.status ).toBe( 0 );
		expect(
			readFileSync(
				join( run.artifacts, 'performance-results.md' ),
				'utf8'
			)
		).toContain( 'NOT A COMPARISON' );
	} );

	test( 'refuses to pair runs that measured different scenarios', () => {
		const other = scenario();
		other.title = 'Homepage › Theme: twentytwentyfive, Locale: en_US';

		const run = compare( [ other ], [ scenario() ] );

		expect( run.status ).toBe( 1 );
		expect( run.stderr ).toContain( 'measured different scenarios' );
	} );

	test( 'refuses to pair runs that measured different metrics', () => {
		const before = scenario();
		delete before.results[ 0 ].wpFilesLoaded;

		const run = compare( [ before ], [ scenario() ] );

		expect( run.status ).toBe( 1 );
		expect( run.stderr ).toContain( 'measured different metrics' );
	} );

	test( 'refuses to pair medians taken over different sample counts', () => {
		const run = compare( [ scenario( {}, 4 ) ], [ scenario( {}, 2 ) ] );

		expect( run.status ).toBe( 1 );
		expect( run.stderr ).toContain( 'samples after' );
	} );

	/*
	 * Two layers reject this input, and the assertion names the one that reports it.
	 * `validateResults()` in utils.js runs first, while each artifact is parsed, so its
	 * message is the one that reaches stderr. `compare-results.js` keeps an equivalent
	 * guard of its own for any caller that assembles stats without going through
	 * validation; that guard is unreachable from this path by design, not by accident,
	 * so asserting its wording here would pin a message the CLI never prints.
	 */
	test( 'refuses a series that holds a different sample count per metric', () => {
		const run = compare(
			[ scenario() ],
			[ scenario( { wpFilesLoaded: [ 383 ] } ) ]
		);

		expect( run.status ).toBe( 1 );
		expect( run.stderr ).toContain(
			'the medians would be taken over different iteration counts'
		);
	} );

	test( 'refuses to compare two different environments', () => {
		const run = compare(
			[ scenario() ],
			[ scenario( { wpOpcacheEnabled: [ 0, 0 ] } ) ]
		);

		expect( run.status ).toBe( 1 );
		expect( run.stderr ).toContain( 'did not run in the same environment' );
	} );
} );

test.describe( 'Performance reporter contract', () => {
	const attachment = {
		name: 'results',
		contentType: 'application/json',
		body: Buffer.from( JSON.stringify( { wpFilesLoaded: [ 373 ] } ) ),
	};

	/*
	 * The reporter reads two variables out of the environment, and both have to be
	 * pinned for these tests to describe the reporter rather than the shell they were
	 * started from. WP_ARTIFACTS_PATH decides the directory, and TEST_RESULTS_PREFIX
	 * decides the file name - a baseline arm runs with it set, which renames the file
	 * these tests assert on and would otherwise turn a passing contract into a failure
	 * that has nothing to do with the reporter. Both are saved and restored around every
	 * test below, so the suite behaves identically whether or not a prefix is in effect.
	 */
	const previousArtifactsPath = process.env.WP_ARTIFACTS_PATH;
	const previousResultsPrefix = process.env.TEST_RESULTS_PREFIX;

	/**
	 * Points the reporter at a directory with no results-file prefix in effect.
	 *
	 * @param {string} artifacts Directory the reporter writes to.
	 */
	function isolateReporterEnvironment( artifacts ) {
		process.env.WP_ARTIFACTS_PATH = artifacts;
		delete process.env.TEST_RESULTS_PREFIX;
	}

	/**
	 * Restores the two environment variables the reporter reads.
	 */
	function restoreReporterEnvironment() {
		process.env.WP_ARTIFACTS_PATH = previousArtifactsPath;

		if ( undefined === previousResultsPrefix ) {
			delete process.env.TEST_RESULTS_PREFIX;
		} else {
			process.env.TEST_RESULTS_PREFIX = previousResultsPrefix;
		}
	}

	/**
	 * Builds a reporter that has ingested one measured scenario.
	 *
	 * @param {string} artifacts Directory the reporter writes to.
	 * @return {PerformanceReporter} Reporter.
	 */
	function reporterWithOneResult( artifacts ) {
		isolateReporterEnvironment( artifacts );

		const reporter = new PerformanceReporter();

		reporter.onTestEnd(
			{
				titlePath: () => [
					'',
					'chromium',
					'home.test.js',
					'Homepage › Theme: twentytwentyone, Locale: en_US',
					'Measure load time metrics (1 of 1)',
				],
				location: {
					file: join(
						process.cwd(),
						'tests',
						'performance',
						'specs',
						'home.test.js'
					),
				},
			},
			{ attachments: [ attachment ] }
		);

		return reporter;
	}

	test( 'writes no results for a run that did not pass', () => {
		const artifacts = mkdtempSync(
			join( tmpdir(), 'wp-performance-reporter-' )
		);

		try {
			reporterWithOneResult( artifacts ).onEnd( { status: 'failed' } );

			expect(
				existsSync( join( artifacts, 'performance-results.json' ) ),
				'a partial run must not leave results behind'
			).toBe( false );
		} finally {
			restoreReporterEnvironment();
		}
	} );

	test( 'writes no results for a run that measured nothing', () => {
		const artifacts = mkdtempSync(
			join( tmpdir(), 'wp-performance-reporter-' )
		);

		try {
			isolateReporterEnvironment( artifacts );

			new PerformanceReporter().onEnd( { status: 'passed' } );

			expect(
				existsSync( join( artifacts, 'performance-results.json' ) ),
				'an empty run must not replace an existing artifact'
			).toBe( false );
		} finally {
			restoreReporterEnvironment();
		}
	} );

	test( 'records the spec relative to the checkout', () => {
		const artifacts = mkdtempSync(
			join( tmpdir(), 'wp-performance-reporter-' )
		);

		try {
			reporterWithOneResult( artifacts ).onEnd( { status: 'passed' } );

			const written = JSON.parse(
				readFileSync(
					join( artifacts, 'performance-results.json' ),
					'utf8'
				)
			);

			expect( written ).toHaveLength( 1 );
			expect( written[ 0 ].file ).toBe(
				'tests/performance/specs/home.test.js'
			);
			expect( written[ 0 ].results ).toEqual( [
				{ wpFilesLoaded: [ 373 ] },
			] );
		} finally {
			restoreReporterEnvironment();
		}
	} );

	test( 'names the results file after the prefix the arm was run with', () => {
		const artifacts = mkdtempSync(
			join( tmpdir(), 'wp-performance-reporter-' )
		);

		try {
			const reporter = reporterWithOneResult( artifacts );

			process.env.TEST_RESULTS_PREFIX = 'before';

			reporter.onEnd( { status: 'passed' } );

			/*
			 * The baseline arm is the one arm that runs with a prefix, and the
			 * comparison reads the two arms out of two differently named files. A
			 * prefix that stopped being honoured would overwrite the after arm with
			 * the before arm and the comparator would report a tree against itself.
			 */
			expect(
				existsSync(
					join( artifacts, 'before-performance-results.json' )
				),
				'a prefixed arm must write the prefixed artifact'
			).toBe( true );
			expect(
				existsSync( join( artifacts, 'performance-results.json' ) ),
				'a prefixed arm must not write the unprefixed artifact'
			).toBe( false );
		} finally {
			restoreReporterEnvironment();
		}
	} );
} );

test.describe( 'Performance evidence hygiene', () => {
	test( 'keeps the authenticated session out of the published artifacts', () => {
		expect( typeof performanceConfig.use.storageState ).toBe( 'string' );

		/*
		 * The storage state is a usable admin session. The performance workflow
		 * uploads WP_ARTIFACTS_PATH wholesale, so a session written inside it is
		 * published with the results.
		 */
		expect(
			performanceConfig.use.storageState.startsWith(
				process.env.WP_ARTIFACTS_PATH
			)
		).toBe( false );
		expect( performanceConfig.globalTeardown ).toBeTruthy();
	} );

	test( 'deletes the run secrets once the run is over', () => {
		const directory = mkdtempSync(
			join( tmpdir(), 'wp-performance-run-secrets-' )
		);
		const storageState = join( directory, 'admin.json' );
		const tokenFile = join( directory, 'performance-cache-reset-token' );

		writeFileSync(
			storageState,
			JSON.stringify( { cookies: [], origins: [] } )
		);
		writeFileSync( tokenFile, 'a'.repeat( 64 ) );

		/*
		 * Both secrets are redirected into the sandbox for the duration of the call.
		 * The teardown withdraws the cache reset token from wherever this variable
		 * points, and this suite runs in the same worker as the measuring specs, so
		 * leaving it pointed at the run's own token would revoke the endpoint those
		 * specs are still resetting through.
		 */
		const configured = process.env.WP_PERF_CACHE_RESET_TOKEN_FILE;
		process.env.WP_PERF_CACHE_RESET_TOKEN_FILE = tokenFile;

		try {
			globalTeardown( { projects: [ { use: { storageState } } ] } );

			expect( existsSync( storageState ) ).toBe( false );

			/*
			 * The token is what enables the mu-plugin's reset endpoint at all, so
			 * withdrawing it is what keeps that endpoint from outliving the run.
			 */
			expect( existsSync( tokenFile ) ).toBe( false );

			// A missing file is the desired end state, so running twice is not an error.
			expect( () =>
				globalTeardown( { projects: [ { use: { storageState } } ] } )
			).not.toThrow();
		} finally {
			if ( undefined === configured ) {
				delete process.env.WP_PERF_CACHE_RESET_TOKEN_FILE;
			} else {
				process.env.WP_PERF_CACHE_RESET_TOKEN_FILE = configured;
			}
		}
	} );
} );
