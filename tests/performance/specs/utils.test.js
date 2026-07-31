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
 * @return {string} One of 'count', 'flag', 'MB', 'ms', or the unexpected output.
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
