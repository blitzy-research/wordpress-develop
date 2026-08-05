/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';

/**
 * Internal dependencies
 */
import {
	accumulateValues,
	camelCaseDashes,
	formatValue,
	getJavaScriptResponseByteSizes,
	median,
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
 * Sources of the specs that attach measured results to the run.
 *
 * Cardinality cannot be observed from inside a spec: Playwright gives every
 * test.afterAll() hook its own TestInfo, so a hook cannot see an attachment made
 * by a sibling hook, and the reporter records only the first 'results' attachment
 * it finds on a test result. A duplicate hook is therefore silent until a test in
 * the same repetition fails, at which point the reset snapshot reaches the artifact
 * and compare-results.js suppresses the whole scenario. Reading the sources is what
 * makes a second attachment hook fail immediately instead.
 */
const attachingSpecs = [ 'admin', 'home', 'single-post' ].map( ( name ) => [
	`${ name }.test.js`,
	readFileSync( join( __dirname, `${ name }.test.js` ), 'utf8' ),
] );

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
	wpPhpVersionId: 'version',
	wpProcessId: 'process',
	wpProcessRequests: 'count',
	wpOpcacheCachedScripts: 'count',
	wpOpcacheHitRate: 'percent',
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

	if ( formatted.endsWith( ' %' ) ) {
		return 'percent';
	}

	if ( formatted.startsWith( 'PHP ' ) ) {
		return 'version';
	}

	if ( formatted.startsWith( 'PID ' ) ) {
		return 'process';
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
				[ 'wp-php-version-id', 'wpPhpVersionId' ],
				[ 'wp-process-id', 'wpProcessId' ],
				[ 'wp-process-requests', 'wpProcessRequests' ],
				[ 'wp-opcache-cached-scripts', 'wpOpcacheCachedScripts' ],
				[ 'wp-opcache-hit-rate', 'wpOpcacheHitRate' ],
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
				[ 'wpPhpVersionId', 80509, 'PHP 8.5.9' ],
				[ 'wpProcessId', 1234, 'PID 1234' ],
				[ 'wpProcessRequests', 17, 17 ],
				[ 'wpOpcacheCachedScripts', 321, 321 ],
				[ 'wpOpcacheHitRate', 98.7654, '98.77 %' ],
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
			expect( formatValue( 'wpPhpVersionId', null ) ).toBe( 'N/A' );
			expect( formatValue( 'adminJsGzipped', null ) ).toBe( 'N/A' );
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

		test( 'the admin spec validates the snapshot before attaching it', () => {
			/*
			 * The duplicate hook was found in the admin spec, so that is the spec
			 * whose hook is ordered to validate first: a duplicate of it would then
			 * fail on the reset arrays rather than attach them. The other two specs
			 * rely on the count above instead, which is what the finding asked for
			 * and leaves their passing hooks untouched.
			 */
			const source = attachingSpecs.find(
				( [ name ] ) => 'admin.test.js' === name
			)[ 1 ];

			expect( source.indexOf( '.toBe( iterations )' ) ).toBeLessThan(
				source.indexOf( "attach( 'results'" )
			);
		} );
	} );

	test.describe( 'unusable samples', () => {
		test( 'are indistinguishable from measurements once reported', () => {
			// A metric that stops being emitted leaves no samples to take a median of.
			expect( median( [] ) ).toBeNaN();

			// The result still formats into a report cell instead of failing.
			expect( formatValue( 'wpBootstrap', NaN ) ).toBe( 'NaN ms' );
			expect( formatValue( 'wpFilesLoaded', NaN ) ).toBeNaN();

			// Attachments are serialized as JSON, where both undefined and NaN become null.
			expect(
				JSON.parse(
					JSON.stringify( { wpFilesLoaded: [ undefined, NaN ] } )
				)
			).toEqual( { wpFilesLoaded: [ null, null ] } );

			// A repetition that is missing a metric silently shortens its series.
			expect(
				accumulateValues( [ { wpFilesLoaded: [ 484 ] }, {} ] )
			).toEqual( { wpFilesLoaded: [ 484 ] } );
		} );
	} );
} );
