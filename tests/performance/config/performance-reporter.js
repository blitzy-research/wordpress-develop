/**
 * External dependencies
 */
import { join, relative, sep } from 'node:path';
import { writeFileSync, existsSync, mkdirSync, rmSync } from 'node:fs';

/**
 * Converts an absolute spec path into a repository-relative, POSIX-separated one.
 *
 * Playwright reports absolute paths, which embed the absolute location of the
 * checkout that produced the run. Recording them verbatim would publish the host
 * directory layout in every generated artifact without adding anything a reader
 * of the artifact can act on, so the repository-relative form is recorded
 * instead. It keeps the debugging value of naming the spec while making the
 * artifact identical across checkouts.
 *
 * The npm scripts that run this suite execute from the repository root, so the
 * working directory is the root to relativise against. Anything that does not
 * resolve beneath it is reduced to its bare file name, so no branch can emit an
 * absolute path.
 *
 * Note that this deliberately avoids `import.meta`: Playwright loads reporters
 * through a CommonJS transform, and an `import.meta` reference in this file
 * makes that transform emit `exports` into an ES module scope, which throws
 * before any test runs.
 *
 * @param {string} absolutePath Absolute path as reported by Playwright.
 * @return {string} Repository-relative path, or the bare file name when the spec
 *                  resolves outside the working directory.
 */
function toRepositoryRelativePath( absolutePath ) {
	const relativePath = relative( process.cwd(), absolutePath );

	// A spec outside the working directory relativises to a parent traversal,
	// which would still describe the surrounding layout. Record only its name.
	if ( ! relativePath || relativePath.startsWith( '..' ) ) {
		return absolutePath.split( /[\\/]/ ).pop();
	}

	return relativePath.split( sep ).join( '/' );
}

/**
 * Resolves the results file the current run reads and writes.
 *
 * Both variables are read here rather than at each use, so the file a refused run
 * discards is by construction the same one a successful run would have written.
 * TEST_RESULTS_PREFIX is what distinguishes the arms of a comparison, so honouring
 * it here is also what keeps one arm from ever touching another's artifact.
 *
 * @return {string|null} Absolute path, or null when no artifacts directory is configured.
 */
function resultsFilePath() {
	const artifactsPath = process.env.WP_ARTIFACTS_PATH;

	if ( 'string' !== typeof artifactsPath || '' === artifactsPath ) {
		return null;
	}

	const prefix = process.env.TEST_RESULTS_PREFIX;

	return join(
		artifactsPath,
		`${ prefix ? `${ prefix }-` : '' }performance-results.json`
	);
}

/**
 * Removes the results file of the arm that has just refused to write one.
 *
 * Refusing to write is not enough on its own: the file is not addressed by the run
 * that produced it, so a results file left over from an earlier run stays exactly
 * where the comparison looks for it, and `tests/performance/compare-results.js`
 * would report medians for a code state that was never measured - with nothing in
 * the output to indicate it. Discarding it turns that silent substitution into the
 * missing-file error the comparison already raises.
 *
 * Only this arm's own file is removed, and only a file: `force` makes an absent one
 * a no-op, which is the normal case.
 *
 * @param {string|null} file Results file resolved for this arm, or null when there is none.
 * @return {void}
 */
function discardResults( file ) {
	if ( null === file ) {
		return;
	}

	rmSync( file, { force: true } );
}

/**
 * @implements {import('@playwright/test/reporter').Reporter}
 */
class PerformanceReporter {
	/**
	 *
	 * @type {Record<string,{title: string; results: Record< string, number[] >[];}>}
	 */
	allResults = {};

	/**
	 * Called after a test has been finished in the worker process.
	 *
	 * Used to add test results to the final summary of all tests.
	 *
	 * @param {import('@playwright/test/reporter').TestCase} test
	 * @param {import('@playwright/test/reporter').TestResult} result
	 */
	onTestEnd( test, result ) {
		const performanceResults = result.attachments.find(
			( attachment ) => attachment.name === 'results'
		);

		if ( performanceResults?.body ) {
			// 0 = empty, 1 = browser, 2 = file name, 3 = test suite name, 4 = test name.
			const titlePath = test.titlePath();
			const title = `${ titlePath[ 3 ] } › ${ titlePath[ 4 ] }`;

			// results is an array in case repeatEach is > 1.

			this.allResults[ title ] ??= {
				/*
				 * Relative to the checkout, because the artifact is published: an
				 * absolute path records the directory the run happened in, which
				 * identifies the machine and the workspace rather than the spec, and
				 * says nothing a reader of the results needs. Unused by the reporting
				 * layer, but useful for debugging.
				 */
				file: toRepositoryRelativePath( test.location.file ),
				results: [],
			};

			this.allResults[ title ].results.push(
				JSON.parse( performanceResults.body.toString( 'utf-8' ) )
			);
		}
	}

	/**
	 * Called after all tests have been run, or testing has been interrupted.
	 *
	 * Writes all raw numbers to a file for further processing,
	 * for example to compare with a previous run.
	 *
	 * Nothing is written unless the whole run passed. A failed, timed out or
	 * interrupted run still collects an attachment from every scenario that finished,
	 * and a file holding those is indistinguishable from a complete one: it is valid
	 * JSON, it carries every scenario the run reached, and the comparison it feeds
	 * would report medians over whichever iterations happened to run. Refusing to
	 * write it is what keeps a partial run from being read as a measurement, and it
	 * also protects a baseline that is already on disk from being replaced by one.
	 *
	 * A refusal discards the results file this arm would have written, because the
	 * alternative is worse than writing nothing: an earlier run's file survives in
	 * the one place the comparison reads, so the medians reported would describe a
	 * code state this run never measured, with nothing in the output to say so.
	 * Only this arm's own file is discarded, so a baseline arm and the arm it is
	 * compared against cannot reach each other's artifact.
	 *
	 * @param {import('@playwright/test/reporter').FullResult} result
	 */
	onEnd( result ) {
		const resultsFile = resultsFilePath();

		if ( 'passed' !== result.status ) {
			console.error(
				`Performance results were not written: the run ${ result.status }, so its measurements are incomplete.`
			);

			discardResults( resultsFile );

			return;
		}

		const summary = [];

		for ( const [ title, { file, results } ] of Object.entries(
			this.allResults
		) ) {
			summary.push( {
				file,
				title,
				results,
			} );
		}

		/*
		 * A run that measured nothing - because every scenario was filtered out, or
		 * because no spec attached its results - would otherwise write an empty array,
		 * which is a valid file that replaces whatever was there before and describes
		 * no code state at all.
		 */
		if ( 0 === summary.length ) {
			console.error(
				'Performance results were not written: the run measured no scenarios.'
			);

			discardResults( resultsFile );

			return;
		}

		if ( null === resultsFile ) {
			throw new Error(
				'Performance results cannot be written: WP_ARTIFACTS_PATH names no directory to write them to.'
			);
		}

		if ( ! existsSync( process.env.WP_ARTIFACTS_PATH ) ) {
			mkdirSync( process.env.WP_ARTIFACTS_PATH );
		}

		writeFileSync( resultsFile, JSON.stringify( summary, null, 2 ) );
	}
}

export default PerformanceReporter;
