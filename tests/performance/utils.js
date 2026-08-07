/**
 * External dependencies.
 */
const { randomBytes } = require( 'node:crypto' );
const {
	existsSync,
	mkdirSync,
	readFileSync,
	rmSync,
	writeFileSync,
} = require( 'node:fs' );
const { dirname, join } = require( 'node:path' );
const { gzipSync } = require( 'node:zlib' );

process.env.WP_ARTIFACTS_PATH ??= join( process.cwd(), 'artifacts' );

const locales = [ 'en_US', 'de_DE' ];

const themes = [
	'twentytwentyone',
	'twentytwentythree',
	'twentytwentyfour',
	'twentytwentyfive',
];

const booleanMetrics = new Set( [
	'wpExtObjCache',
	'wpOpcacheEnabled',
	'wpOpcacheJit',
] );

const countMetrics = new Set( [
	'wpDbQueries',
	'wpFilesLoaded',
	'wpCacheHits',
	'wpCacheMisses',
	/*
	 * 1 when wpBootstrap was measured to its own 'wp_loaded' boundary, 0 when it was
	 * never reached. Passed through as a number so the comparison table shows the
	 * count of valid samples rather than rendering the flag as a duration.
	 */
	'wpBootstrapValid',
] );

/**
 * Metrics whose value states whether another metric was measured at all.
 *
 * A validity flag is a count of usable samples rather than a quantity, so it is
 * formatted as a raw number but must not be differenced: subtracting one run's
 * validity from another's produces a number that reads like a regression and means
 * nothing. Membership here leaves the difference, STD and MAD cells empty while the
 * value itself is still reported, which is what makes an invalid run visible.
 */
const validityMetrics = new Set( [ 'wpBootstrapValid' ] );

/**
 * Status the cache-reset helper answers an authorized reset with, and nothing else does.
 *
 * `tests/performance/wp-content/mu-plugins/server-timing.php` answers an authorized
 * `POST /?clear_cache` with 202 after discarding the opcode cache, the object cache and
 * the expired transients. WordPress itself never sends 202 for that URL, and neither
 * does any status the reset refuses with, so requiring it is what distinguishes a
 * request that was actually reset from one that was refused, or from a front page that
 * merely returned 200 because no reset helper was installed.
 */
const CACHE_RESET_STATUS = 202;

/**
 * Header the cache-reset secret is presented in.
 *
 * A header rather than a query argument, because a reset changes server state for the
 * whole installation: a secret in the URL would be sent by any navigation, prefetch or
 * embedded resource that copied the address, and would be recorded in the access log,
 * the referrer and the browser history. A custom header cannot be set by a cross-origin
 * form or an `img` tag at all, which is what makes the endpoint unreachable by tricking
 * a browser. PHP exposes this name as `$_SERVER['HTTP_X_WP_PERF_CACHE_RESET_TOKEN']`,
 * which is where the mu-plugin reads it.
 */
const CACHE_RESET_TOKEN_HEADER = 'X-WP-Perf-Cache-Reset-Token';

/**
 * Grammar the mu-plugin requires of the secret before it will enable the reset at all.
 *
 * Kept identical to the pattern in `server-timing.php` on purpose: a token this side
 * writes but that side would reject leaves the endpoint disabled, and the failure would
 * surface as an unexplained 404 in the middle of a measured run rather than here.
 */
const CACHE_RESET_TOKEN_PATTERN = /^[A-Za-z0-9]{32,128}$/;

/**
 * Where the per-run secret is kept.
 *
 * Beside the installation directory rather than inside it, so it is not served over
 * HTTP: the WordPress document root is `<repo>/src` or `<repo>/build`, and this sits in
 * `<repo>/.cache`, which is git-ignored. Resolved from this file's own location rather
 * than from `process.cwd()` so that it is the same path no matter which directory the
 * suite was started from, and it is the same path the mu-plugin derives from `ABSPATH`.
 * `WP_PERF_CACHE_RESET_TOKEN_FILE` overrides it, and the mu-plugin honours the same
 * variable, for a deployment whose installation is not inside this repository.
 *
 * @return {string} Absolute path of the token file.
 */
function cacheResetTokenPath() {
	return (
		process.env.WP_PERF_CACHE_RESET_TOKEN_FILE ||
		join( __dirname, '..', '..', '.cache', 'performance-cache-reset-token' )
	);
}

/**
 * Provisions the secret that authorizes this run's cache resets.
 *
 * The mu-plugin has no reset endpoint until this file exists, so writing it is the
 * explicit, deliberate enable step for the control plane, and `globalTeardown` deletes
 * it again once the workers are done. Nothing is provisioned for an installation that
 * merely has the mu-plugin present.
 *
 * Created exclusively, so that two processes reaching this at the same time cannot end
 * up disagreeing about the secret: whoever loses the race reads the winner's file
 * instead of overwriting it. The mode is deliberately world readable, because PHP-FPM
 * runs as a different user than the test runner and has to read it; the file is outside
 * the document root, so the value is reachable by a local process and by nothing over
 * the network.
 *
 * The value itself is never returned to a caller that logs, never interpolated into a
 * URL and never attached to a test result.
 *
 * @return {string} The secret for this run.
 */
function cacheResetToken() {
	if ( cacheResetToken.token ) {
		return cacheResetToken.token;
	}

	const path = cacheResetTokenPath();

	const readProvisioned = () => {
		const provisioned = readFileSync( path, 'utf8' ).trim();

		if ( ! CACHE_RESET_TOKEN_PATTERN.test( provisioned ) ) {
			throw new Error(
				`The cache reset token at ${ path } does not match ${ CACHE_RESET_TOKEN_PATTERN }, so tests/performance/wp-content/mu-plugins/server-timing.php will refuse every reset and leave the caches warm. Delete the file and run the suite again.`
			);
		}

		return provisioned;
	};

	if ( existsSync( path ) ) {
		cacheResetToken.token = readProvisioned();

		return cacheResetToken.token;
	}

	mkdirSync( dirname( path ), { recursive: true } );

	try {
		// 32 random bytes, hex encoded, so the value satisfies the grammar above.
		const token = randomBytes( 32 ).toString( 'hex' );

		writeFileSync( path, token, { encoding: 'utf8', flag: 'wx', mode: 0o644 } );

		cacheResetToken.token = token;
	} catch ( error ) {
		if ( 'EEXIST' !== error.code ) {
			throw error;
		}

		// Another process provisioned it first; its value is the one the server will accept.
		cacheResetToken.token = readProvisioned();
	}

	return cacheResetToken.token;
}

/**
 * Withdraws the secret, and with it the reset endpoint.
 *
 * Called from the global teardown so the control plane exists for exactly the duration
 * of one measured run. The next run provisions a fresh secret, so no value outlives the
 * run that created it.
 *
 * Only the file is removed. Its directory holds other build caches, so removing the
 * directory could take something else with it.
 *
 * @return {void}
 */
function revokeCacheResetToken() {
	delete cacheResetToken.token;

	rmSync( cacheResetTokenPath(), { force: true } );
}

/**
 * Discards the caches the next measured navigation would otherwise be served from.
 *
 * Every measured sample in this suite is reported as an uncached, cold-compile
 * measurement, and that label is only true if the reset actually happened. Ignoring
 * the response let a missing mu-plugin publish warm samples under an uncached label,
 * so the status is asserted here, once, for every spec.
 *
 * A POST through the request API rather than a navigation, because the reset is a
 * state-changing operation that has to be unreachable from anything a browser will do
 * on its own. The page is deliberately left where it is: the caller navigates to the
 * URL it means to measure immediately afterwards, so a navigation here would only add a
 * document nobody looks at.
 *
 * @param {import('@playwright/test').Page} page Page whose request context to use.
 * @return {Promise<void>} Resolves once the caches have been discarded.
 */
async function clearServerCaches( page ) {
	// Not actually loading a page: the response body is empty by design.
	const response = await page.request.post( '/?clear_cache', {
		headers: { [ CACHE_RESET_TOKEN_HEADER ]: cacheResetToken() },
	} );

	const status = response.status();

	if ( CACHE_RESET_STATUS === status ) {
		return;
	}

	/*
	 * Each refusal has one cause, and the mu-plugin answers each with a status of its
	 * own, so the reason is reported rather than guessed. The secret is never included
	 * in any of these messages: the failures are all explained by which file exists and
	 * which method was used, and a message that quoted the value would put it into the
	 * run's log and its uploaded artifacts.
	 */
	const reasons = {
		404: `no cache reset endpoint answered. Either tests/performance/wp-content/mu-plugins/server-timing.php is not installed in the WordPress tree under test, or it could not read the token at ${ cacheResetTokenPath() } — check that the path is beside the installation directory and readable by the web server user.`,
		405: 'the cache reset endpoint refused the request method. It accepts POST only, so this helper must not navigate to the URL.',
		403: `the cache reset endpoint refused the presented token. The value at ${ cacheResetTokenPath() } is not the one the server resolved; a WP_PERF_CACHE_RESET_TOKEN constant or environment variable takes precedence over the file and may be stale.`,
	};

	throw new Error(
		`Requesting a cache reset answered ${ status } where ${ CACHE_RESET_STATUS } was required: ${
			reasons[ status ] ?? 'the request was not answered by the cache reset endpoint.'
		} The opcode cache, object cache and transients were therefore not discarded, and every sample taken after this point would be warm.`
	);
}

/**
 * Reports the first reason a measured series cannot be aggregated.
 *
 * A series reaches the report through a median, and a median only means something
 * when every sample is a real number and there is at least one of them. An empty
 * series medians to NaN and a null sample sorts as 0, so both survive all the way
 * into a results cell and are indistinguishable there from a measurement.
 *
 * @param {unknown} samples Candidate series.
 * @return {string|null} Reason the series is unusable, or null when it is usable.
 */
function invalidSeriesReason( samples ) {
	if ( ! Array.isArray( samples ) ) {
		return `expected an array of samples, received ${ typeof samples }`;
	}

	if ( 0 === samples.length ) {
		return 'holds no samples, so it has no median';
	}

	for ( const [ index, sample ] of samples.entries() ) {
		if ( 'number' !== typeof sample || ! Number.isFinite( sample ) ) {
			return `sample ${ index } is ${ JSON.stringify(
				sample
			) }, which is not a finite number`;
		}
	}

	return null;
}

/**
 * Rejects a performance results artifact that cannot be compared.
 *
 * The comparison reads the first scenario, its first repetition and that
 * repetition's first metric without checking that any of them exist, and it takes a
 * median of whatever series it finds. So a truncated run, a scenario that lost a
 * metric, a repetition measured a different number of times and a series holding a
 * null all reach the results table as numbers rather than as failures. Everything
 * the comparison assumes is therefore asserted here, at the one point where the
 * artifact enters the reporter.
 *
 * @param {unknown} stats    Parsed artifact contents.
 * @param {string}  fileName Artifact file name, for the failure message.
 * @return {Array<{file: string, title: string, results: Record<string,number[]>[]}>} The validated artifact.
 */
function validateResults( stats, fileName ) {
	const fail = ( reason ) => {
		throw new Error( `${ fileName }: ${ reason }` );
	};

	if ( ! Array.isArray( stats ) ) {
		fail( `expected an array of scenarios, received ${ typeof stats }` );
	}

	if ( 0 === stats.length ) {
		fail( 'holds no scenarios, so there is nothing to compare' );
	}

	const titles = new Set();

	for ( const [ index, scenario ] of stats.entries() ) {
		if ( null === scenario || 'object' !== typeof scenario ) {
			fail( `scenario ${ index } is not an object` );
		}

		const { title, results } = scenario;

		if ( 'string' !== typeof title || '' === title ) {
			fail( `scenario ${ index } has no title` );
		}

		if ( titles.has( title ) ) {
			fail(
				`scenario '${ title }' appears more than once, so one of its result sets would be discarded`
			);
		}
		titles.add( title );

		if ( ! Array.isArray( results ) || 0 === results.length ) {
			fail( `scenario '${ title }' holds no repetitions` );
		}

		let metrics = null;
		let samplesPerRepetition = null;

		for ( const [ repetition, measured ] of results.entries() ) {
			if ( null === measured || 'object' !== typeof measured ) {
				fail(
					`scenario '${ title }' repetition ${ repetition } is not an object`
				);
			}

			const keys = Object.keys( measured ).sort();

			if ( 0 === keys.length ) {
				fail(
					`scenario '${ title }' repetition ${ repetition } reports no metrics`
				);
			}

			if ( null === metrics ) {
				metrics = keys;
			} else if ( keys.join( ',' ) !== metrics.join( ',' ) ) {
				fail(
					`scenario '${ title }' repetition ${ repetition } reports [${ keys }] where repetition 0 reports [${ metrics }]; a metric present in one repetition and missing from another shortens its series without shortening the others`
				);
			}

			for ( const metric of keys ) {
				const reason = invalidSeriesReason( measured[ metric ] );

				if ( null !== reason ) {
					fail(
						`scenario '${ title }' repetition ${ repetition } metric '${ metric }' ${ reason }`
					);
				}

				const { length } = measured[ metric ];

				if ( null === samplesPerRepetition ) {
					samplesPerRepetition = length;
				} else if ( length !== samplesPerRepetition ) {
					fail(
						`scenario '${ title }' repetition ${ repetition } metric '${ metric }' holds ${ length } samples where ${ samplesPerRepetition } were measured; the medians would be taken over different iteration counts`
					);
				}
			}
		}
	}

	return stats;
}

/**
 * Parse test files into JSON objects.
 *
 * An absent file is reported as no scenarios, which is how a run with no baseline to
 * compare against is expressed. A file that exists is validated, because from here on
 * every consumer treats its contents as measurements.
 *
 * @param {string} fileName The name of the file.
 * @return {Array<{file: string, title: string, results: Record<string,number[]>[]}>} Parsed object.
 */
function parseFile( fileName ) {
	const file = join( process.env.WP_ARTIFACTS_PATH, fileName );
	if ( ! existsSync( file ) ) {
		return [];
	}

	let parsed;

	try {
		parsed = JSON.parse( readFileSync( file, 'utf8' ) );
	} catch ( error ) {
		throw new Error( `${ fileName }: is not valid JSON: ${ error.message }` );
	}

	return validateResults( parsed, fileName );
}

/**
 * Computes the median number from an array numbers.
 *
 * An unusable series is rejected rather than medianed. An empty array produced NaN,
 * and a null or undefined sample sorted as though it were zero, so either reached the
 * results table looking like a measurement.
 *
 * @param {number[]} array
 *
 * @return {number} Median.
 */
function median( array ) {
	const reason = invalidSeriesReason( array );

	if ( null !== reason ) {
		throw new Error( `Cannot take the median of a series that ${ reason }` );
	}

	const mid = Math.floor( array.length / 2 );
	const numbers = [ ...array ].sort( ( a, b ) => a - b );
	return array.length % 2 !== 0
		? numbers[ mid ]
		: ( numbers[ mid - 1 ] + numbers[ mid ] ) / 2;
}

function camelCaseDashes( str ) {
	return str.replace( /-([a-z])/g, function ( g ) {
		return g[ 1 ].toUpperCase();
	} );
}

/**
 * Formats an array of objects as a Markdown table.
 *
 * For example, this array:
 *
 * [
 * 	{
 * 	    foo: 123,
 * 	    bar: 456,
 * 	    baz: 'Yes',
 * 	},
 * 	{
 * 	    foo: 777,
 * 	    bar: 999,
 * 	    baz: 'No',
 * 	}
 * ]
 *
 * Will result in the following table:
 *
 * | foo | bar | baz |
 * |-----|-----|-----|
 * | 123 | 456 | Yes |
 * | 777 | 999 | No  |
 *
 * @param {Array<Object>} rows Table rows.
 * @return {string} Markdown table content.
 */
function formatAsMarkdownTable( rows ) {
	let result = '';

	if ( ! rows.length ) {
		return result;
	}

	const headers = Object.keys( rows[ 0 ] );
	for ( const header of headers ) {
		result += `| ${ header } `;
	}
	result += '|\n';
	for ( let i = 0; i < headers.length; i++ ) {
		result += '| ------ ';
	}
	result += '|\n';

	for ( const row of rows ) {
		for ( const value of Object.values( row ) ) {
			result += `| ${ value } `;
		}
		result += '|\n';
	}

	return result;
}

/**
 * Nicely formats a given value.
 *
 * @param {string} metric Metric.
 * @param {number} value
 */
function formatValue( metric, value ) {
	if ( null === value ) {
		return 'N/A';
	}

	if ( 'wpMemoryUsage' === metric || 'wpMemoryPeak' === metric ) {
		return `${ ( value / Math.pow( 10, 6 ) ).toFixed( 2 ) } MB`;
	}

	if ( 'adminJsRaw' === metric || 'adminJsGzipped' === metric ) {
		return `${ ( value / Math.pow( 10, 3 ) ).toFixed( 2 ) } kB`;
	}

	if ( booleanMetrics.has( metric ) ) {
		return 1 === value ? 'yes' : 'no';
	}

	if ( countMetrics.has( metric ) ) {
		return value;
	}

	return `${ value.toFixed( 2 ) } ms`;
}

/**
 * Determines whether the difference between two values of a metric is meaningful.
 *
 * Flags belong in the comparison because they qualify every other number in their row —
 * an object cache that appeared, an OPcache that was switched off — but they are not
 * quantities. Subtracting, averaging or deviating them is arithmetic on labels, which
 * renders as '-1' rather than as information, so their difference columns are left empty
 * instead.
 *
 * A validity flag is excluded for the same reason. 'wpBootstrapValid' says whether the
 * bootstrap duration beside it was measured at all, so a difference of -1 between two
 * runs reads as a one-unit regression in a metric that has no units. Its value is still
 * reported, because a run in which it is not 1 is a run whose bootstrap figures must be
 * discarded.
 *
 * @param {string} metric Metric.
 * @return {boolean} Whether a numeric difference between two values of the metric is meaningful.
 */
function isComparableMetric( metric ) {
	return ! booleanMetrics.has( metric ) && ! validityMetrics.has( metric );
}

/**
 * Calculates deterministic raw and gzip-compressed JavaScript response sizes.
 *
 * HTTP servers and browsers can negotiate different transfer encodings, so the
 * benchmark reads each decoded response body and applies the same gzip level to every
 * sample. Each response is compressed separately, matching how JavaScript assets are
 * transferred over HTTP rather than compressing an artificial concatenated bundle.
 *
 * @param {Array<{body: () => Promise<Buffer>}>} responses JavaScript responses.
 * @return {Promise<{raw: number, gzipped: number}>} Byte totals.
 */
async function getJavaScriptResponseByteSizes( responses ) {
	const bodies = await Promise.all(
		responses.map( ( response ) => response.body() )
	);

	return bodies.reduce(
		( sizes, body ) => {
			sizes.raw += body.byteLength;
			sizes.gzipped += gzipSync( body, { level: 9 } ).byteLength;

			return sizes;
		},
		{ raw: 0, gzipped: 0 }
	);
}

/**
 * Returns a Markdown link to a Git commit on the current GitHub repository.
 *
 * For example, turns `a5c3785ed8d6a35868bc169f07e40e889087fd2e`
 * into (https://github.com/wordpress/wordpress-develop/commit/36fe58a8c64dcc83fc21bddd5fcf054aef4efb27)[36fe58a].
 *
 * @param {string} sha Commit SHA.
 * @return {string} Link.
 */
function linkToSha( sha ) {
	const repoName =
		process.env.GITHUB_REPOSITORY || 'wordpress/wordpress-develop';

	return `[${ sha.slice(
		0,
		7
	) }](https://github.com/${ repoName }/commit/${ sha })`;
}

function standardDeviation( array = [] ) {
	if ( ! array.length ) {
		return 0;
	}

	const mean = array.reduce( ( a, b ) => a + b ) / array.length;
	return Math.sqrt(
		array
			.map( ( x ) => Math.pow( x - mean, 2 ) )
			.reduce( ( a, b ) => a + b ) / array.length
	);
}

function medianAbsoluteDeviation( array = [] ) {
	if ( ! array.length ) {
		return 0;
	}

	const med = median( array );
	return median( array.map( ( a ) => Math.abs( a - med ) ) );
}

/**
 * Merges the per-repetition series of one scenario into one series per metric.
 *
 * Accumulating silently was how a metric that stopped being emitted in one repetition
 * kept a shorter series than its siblings, and how a series holding a null reached the
 * median. Both are rejected here as well as in validateResults(), because this function
 * is also reachable from a caller that assembled its results in memory rather than
 * reading them from an artifact.
 *
 * @param {Array<Record<string, number[]>>} results
 * @return {Record<string, number[]>} Accumulated metric values.
 */
function accumulateValues( results ) {
	if ( ! Array.isArray( results ) || 0 === results.length ) {
		throw new Error(
			'Cannot accumulate a scenario that holds no repetitions.'
		);
	}

	let metrics = null;

	return results.reduce( ( acc, result, repetition ) => {
		if ( null === result || 'object' !== typeof result ) {
			throw new Error( `Repetition ${ repetition } is not an object.` );
		}

		const keys = Object.keys( result ).sort();

		if ( 0 === keys.length ) {
			throw new Error( `Repetition ${ repetition } reports no metrics.` );
		}

		if ( null === metrics ) {
			metrics = keys;
		} else if ( keys.join( ',' ) !== metrics.join( ',' ) ) {
			throw new Error(
				`Repetition ${ repetition } reports [${ keys }] where repetition 0 reports [${ metrics }]; accumulating them would leave the series of different lengths.`
			);
		}

		for ( const metric of keys ) {
			const reason = invalidSeriesReason( result[ metric ] );

			if ( null !== reason ) {
				throw new Error(
					`Repetition ${ repetition } metric '${ metric }' ${ reason }.`
				);
			}

			acc[ metric ] = acc[ metric ] ?? [];
			acc[ metric ].push( ...result[ metric ] );
		}

		return acc;
	}, {} );
}

module.exports = {
	CACHE_RESET_STATUS,
	CACHE_RESET_TOKEN_HEADER,
	CACHE_RESET_TOKEN_PATTERN,
	cacheResetTokenPath,
	cacheResetToken,
	revokeCacheResetToken,
	clearServerCaches,
	invalidSeriesReason,
	validateResults,
	parseFile,
	median,
	camelCaseDashes,
	formatAsMarkdownTable,
	formatValue,
	isComparableMetric,
	getJavaScriptResponseByteSizes,
	linkToSha,
	standardDeviation,
	medianAbsoluteDeviation,
	accumulateValues,
	themes,
	locales,
};
