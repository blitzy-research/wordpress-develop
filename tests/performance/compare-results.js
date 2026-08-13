#!/usr/bin/env node

/**
 * External dependencies.
 */
const { existsSync, readFileSync, writeFileSync } = require( 'node:fs' );
const { join } = require( 'node:path' );

/**
 * Internal dependencies
 */
const {
	validateResults,
	median,
	formatAsMarkdownTable,
	formatValue,
	isComparableMetric,
	booleanMetrics,
	linkToSha,
	standardDeviation,
	medianAbsoluteDeviation,
	accumulateValues,
} = require( './utils' );

process.env.WP_ARTIFACTS_PATH ??= join( process.cwd(), 'artifacts' );

const args = process.argv.slice( 2 );
const summaryFile = args[ 0 ];

/**
 * Aborts the comparison.
 *
 * Every condition that makes a printed difference misleading ends here rather than in a
 * table cell. A comparison that cannot be made is a failure of this tool, not a result:
 * exiting non-zero is what stops an absent baseline, a mismatched scenario set or an
 * undersampled series from being read as proof that a change is harmless.
 *
 * @param {string} message Why the comparison cannot be made.
 * @return {never}
 */
function fail( message ) {
	console.error( `Performance comparison failed: ${ message }` );
	process.exit( 1 );
}

/**
 * Parse test files into JSON objects.
 *
 * @param {string} fileName The name of the file.
 * @return {?Array<{file: string, title: string, results: Record<string,number[]>[]}>} Parsed results, or null when the file does not exist.
 */
function parseFile( fileName ) {
	const file = join( process.env.WP_ARTIFACTS_PATH, fileName );
	if ( ! existsSync( file ) ) {
		return null;
	}

	let parsed;

	try {
		parsed = JSON.parse( readFileSync( file, 'utf8' ) );
	} catch ( error ) {
		return fail( `${ fileName } is not valid JSON: ${ error.message }` );
	}

	if ( ! Array.isArray( parsed ) || 0 === parsed.length ) {
		return fail( `${ fileName } holds no measured scenarios.` );
	}

	/*
	 * Validated here rather than where it is read, so every consumer below can treat
	 * the contents as measurements: an exact and repeated metric set per scenario, an
	 * equal and non-zero sample count per metric, and finite numbers throughout.
	 */
	try {
		return validateResults( parsed, fileName );
	} catch ( error ) {
		return fail( error.message );
	}
}

/**
 * Reports the number of samples each metric of a scenario holds.
 *
 * @param {{title: string, results: Record<string,number[]>[]}} stat     One measured scenario.
 * @param {string}                                             fileName Artifact the scenario came from.
 * @return {{repetitions: number, iterations: number, metrics: string[], samples: Record<string, number>}} Shape of the scenario.
 */
function describeShape( stat, fileName ) {
	if (
		! stat ||
		'string' !== typeof stat.title ||
		! Array.isArray( stat.results )
	) {
		return fail(
			`${ fileName } holds an entry that is not a measured scenario.`
		);
	}

	if ( 0 === stat.results.length ) {
		return fail(
			`${ fileName } holds no repetitions for ${ stat.title }.`
		);
	}

	const metrics = Object.keys( stat.results[ 0 ] ).sort();

	if ( 0 === metrics.length ) {
		return fail( `${ fileName } holds no metrics for ${ stat.title }.` );
	}

	/**
	 * Samples per metric, accumulated over every repetition.
	 *
	 * @type {Record<string, number>}
	 */
	const samples = {};
	const iterationCounts = new Set();

	for ( const [ repetition, result ] of stat.results.entries() ) {
		const repetitionMetrics = Object.keys( result ).sort();

		if ( repetitionMetrics.join( ',' ) !== metrics.join( ',' ) ) {
			return fail(
				`${ fileName } reports different metrics for repetition ${
					repetition + 1
				} of ${ stat.title } than for repetition 1.`
			);
		}

		for ( const metric of metrics ) {
			const values = result[ metric ];

			if ( ! Array.isArray( values ) || 0 === values.length ) {
				return fail(
					`${ fileName } holds no samples for ${ metric } in ${ stat.title }.`
				);
			}

			/*
			 * Every metric of one repetition measures the same iterations, so a metric
			 * that holds a different number of samples than its siblings was either not
			 * emitted on every iteration or accumulated across scenarios. Both make its
			 * median describe a different set of requests than the rest of the row, so
			 * the run is rejected instead of being reported.
			 */
			iterationCounts.add( values.length );
			samples[ metric ] = ( samples[ metric ] ?? 0 ) + values.length;
		}
	}

	if ( 1 !== iterationCounts.size ) {
		return fail(
			`${ fileName } holds an inconsistent number of samples per iteration for ${
				stat.title
			}: ${ [ ...iterationCounts ]
				.sort( ( a, b ) => a - b )
				.join( ', ' ) }.`
		);
	}

	return {
		repetitions: stat.results.length,
		iterations: [ ...iterationCounts ][ 0 ],
		metrics,
		samples,
	};
}

/**
 * @type {?Array<{file: string, title: string, results: Record<string,number[]>[]}>}
 */
const beforeStats = parseFile( 'before-performance-results.json' );

/**
 * @type {?Array<{file: string, title: string, results: Record<string,number[]>[]}>}
 */
const afterStats = parseFile( 'performance-results.json' );

if ( null === afterStats ) {
	fail(
		'performance-results.json is missing, so there is nothing to report. Run the performance suite first.'
	);
}

/*
 * A missing baseline used to produce a table of after values with every difference
 * column reading N/A, and to exit 0 while doing it, which is indistinguishable from a
 * comparison that found no change. Proving an improvement requires both arms, so the
 * absence of one is fatal unless it is declared deliberately - which stamps the output
 * as not being a comparison rather than quietly letting it look like one.
 */
const allowMissingBaseline = [ '1', 'true' ].includes(
	String( process.env.PERFORMANCE_ALLOW_MISSING_BASELINE ).toLowerCase()
);

if ( null === beforeStats && ! allowMissingBaseline ) {
	fail(
		'before-performance-results.json is missing, so no before/after comparison can be made. Copy the baseline run into WP_ARTIFACTS_PATH, or set PERFORMANCE_ALLOW_MISSING_BASELINE=1 to print the after values only.'
	);
}

let summaryMarkdown = `## Performance Test Results\n\n`;

if ( null === beforeStats ) {
	summaryMarkdown += `**NOT A COMPARISON.** No baseline results were available, so the values below describe this run only and no difference is reported.\n\n`;
}

if ( process.env.TARGET_SHA ) {
	if ( null !== beforeStats ) {
		if ( process.env.GITHUB_SHA ) {
			summaryMarkdown += `This compares the results from this commit (${ linkToSha(
				process.env.GITHUB_SHA
			) }) with the ones from ${ linkToSha(
				process.env.TARGET_SHA
			) }.\n\n`;
		} else {
			summaryMarkdown += `This compares the results from this commit with the ones from ${ linkToSha(
				process.env.TARGET_SHA
			) }.\n\n`;
		}
	} else {
		summaryMarkdown += `Note: no build was found for the target commit ${ linkToSha(
			process.env.TARGET_SHA
		) }. No comparison is possible.\n\n`;
	}
}

const afterShapes = new Map(
	afterStats.map( ( stat ) => [
		stat.title,
		describeShape( stat, 'performance-results.json' ),
	] )
);

if ( afterShapes.size !== afterStats.length ) {
	fail(
		'performance-results.json reports the same scenario more than once, so its samples cannot be attributed to one code state.'
	);
}

if ( null !== beforeStats ) {
	const beforeTitles = beforeStats.map( ( stat ) => stat.title );
	const missing = [ ...afterShapes.keys() ].filter(
		( title ) => ! beforeTitles.includes( title )
	);
	const extra = beforeTitles.filter(
		( title ) => ! afterShapes.has( title )
	);

	if ( 0 < missing.length || 0 < extra.length ) {
		fail(
			`the two runs measured different scenarios, so they cannot be compared. Only after: ${
				missing.join( ', ' ) || 'none'
			}. Only before: ${ extra.join( ', ' ) || 'none' }.`
		);
	}

	if ( new Set( beforeTitles ).size !== beforeTitles.length ) {
		fail(
			'before-performance-results.json reports the same scenario more than once, so its samples cannot be attributed to one code state.'
		);
	}
}

const distinctRepetitions = new Set(
	[ ...afterShapes.values() ].map( ( shape ) => shape.repetitions )
);
const distinctIterations = new Set(
	[ ...afterShapes.values() ].map( ( shape ) => shape.iterations )
);

if ( 1 !== distinctRepetitions.size || 1 !== distinctIterations.size ) {
	fail(
		'performance-results.json reports a different number of repetitions or iterations for different scenarios, so one sample count cannot describe the run.'
	);
}

const numberOfRepetitions = [ ...distinctRepetitions ][ 0 ];
const numberOfIterations = [ ...distinctIterations ][ 0 ];

const repetitions = `${ numberOfRepetitions } ${
	numberOfRepetitions === 1 ? 'repetition' : 'repetitions'
}`;
const iterations = `${ numberOfIterations } ${
	numberOfIterations === 1 ? 'iteration' : 'iterations'
}`;

summaryMarkdown += `All numbers are median values over ${ repetitions } with ${ iterations } each.\n\n`;

if ( process.env.GITHUB_SHA ) {
	summaryMarkdown += `**Note:** Due to the nature of how GitHub Actions work, some variance in the results is expected.\n\n`;
}

console.log( 'Performance Test Results\n' );

console.log(
	`All numbers are median values over ${ repetitions } with ${ iterations } each.\n`
);

if ( process.env.GITHUB_SHA ) {
	console.log(
		'Note: Due to the nature of how GitHub Actions work, some variance in the results is expected.\n'
	);
}

summaryMarkdown += `<details><summary>Results</summary>`;

for ( const { title, results } of afterStats ) {
	const prevStat = beforeStats?.find( ( s ) => s.title === title ) ?? null;

	/**
	 * @type {Array<Record<string, string>>}
	 */
	const rows = [];

	const newResults = accumulateValues( results );

	if ( null !== prevStat ) {
		const afterShape = afterShapes.get( title );
		const beforeShape = describeShape(
			prevStat,
			'before-performance-results.json'
		);

		/*
		 * A metric present in one arm and absent from the other cannot be compared, and
		 * pairing what remains would quietly drop the missing one from the proof. Sample
		 * counts have to match too: a median over 20 requests and a median over 6 are two
		 * different measurements, and the difference between them is not a change in the
		 * code.
		 */
		if (
			afterShape.metrics.join( ',' ) !== beforeShape.metrics.join( ',' )
		) {
			fail(
				`the two runs measured different metrics for ${ title }. Only after: ${
					afterShape.metrics
						.filter(
							( metric ) =>
								! beforeShape.metrics.includes( metric )
						)
						.join( ', ' ) || 'none'
				}. Only before: ${
					beforeShape.metrics
						.filter(
							( metric ) =>
								! afterShape.metrics.includes( metric )
						)
						.join( ', ' ) || 'none'
				}.`
			);
		}

		if ( afterShape.repetitions !== beforeShape.repetitions ) {
			fail(
				`${ title } was measured over ${ afterShape.repetitions } repetitions after and ${ beforeShape.repetitions } before.`
			);
		}

		for ( const metric of afterShape.metrics ) {
			if (
				afterShape.samples[ metric ] !== beforeShape.samples[ metric ]
			) {
				fail(
					`${ metric } holds ${ afterShape.samples[ metric ] } samples after and ${ beforeShape.samples[ metric ] } before for ${ title }.`
				);
			}
		}

		/*
		 * A flag metric describes the environment the code ran in, not the code. When the
		 * two arms disagree about one, every difference in the row is a difference between
		 * two environments as much as between two revisions, and none of them can be
		 * attributed: a persistent object cache appearing in the after arm alone removes
		 * queries and moves the heap on its own, which reads in the table as an
		 * improvement the change set did not make. So the pair is refused here rather than
		 * printed with a caveat, before any difference is computed.
		 */
		for ( const metric of afterShape.metrics ) {
			if ( ! booleanMetrics.has( metric ) ) {
				continue;
			}

			const afterFlag = median( newResults[ metric ] );
			const beforeFlag = median(
				accumulateValues( prevStat.results )[ metric ]
			);

			if ( afterFlag !== beforeFlag ) {
				fail(
					`${ title } was measured with ${ metric } ${ formatValue(
						metric,
						beforeFlag
					) } before and ${ formatValue(
						metric,
						afterFlag
					) } after, so the two runs describe different environments and no difference between them can be attributed to the code.`
				);
			}
		}
	}

	const prevResults =
		null !== prevStat ? accumulateValues( prevStat.results ) : {};

	for ( const [ metric, values ] of Object.entries( newResults ) ) {
		const prevValues = prevResults[ metric ] ? prevResults[ metric ] : null;

		const value = median( values );
		const prevValue = prevValues ? median( prevValues ) : 0;
		const delta = value - prevValue;
		/*
		 * Relative to the before value, which is what "N% reduction" means. Dividing by
		 * the after value instead reports a larger magnitude for every reduction - a
		 * fall from 500 to 383 reads as -30.55% rather than -23.40% - which is the
		 * difference between meeting a 30% target and missing it.
		 */
		const percentage = 0 === prevValue ? NaN : ( delta / prevValue ) * 100;
		const showDiff =
			isComparableMetric( metric ) &&
			null !== prevValues &&
			! Number.isNaN( percentage );

		rows.push( {
			Metric: metric,
			Before: prevValues ? formatValue( metric, prevValue ) : 'N/A',
			After: formatValue( metric, value ),
			'Diff abs.': showDiff ? formatValue( metric, delta ) : '',
			'Diff %': showDiff ? `${ percentage.toFixed( 2 ) } %` : '',
			STD: showDiff
				? formatValue( metric, standardDeviation( values ) )
				: '',
			MAD: showDiff
				? formatValue( metric, medianAbsoluteDeviation( values ) )
				: '',
		} );
	}

	console.log( title );
	if ( rows.length > 0 ) {
		console.table( rows );
	} else {
		console.log( '(no results)' );
	}

	summaryMarkdown += `<b>${ title }</b>\n\n`;
	summaryMarkdown += `${ formatAsMarkdownTable( rows ) }\n`;
}

summaryMarkdown += `</details>`;

writeFileSync(
	join( process.env.WP_ARTIFACTS_PATH, '/performance-results.md' ),
	summaryMarkdown
);

if ( summaryFile ) {
	writeFileSync( summaryFile, summaryMarkdown );
}
