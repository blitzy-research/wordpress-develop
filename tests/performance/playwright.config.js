/**
 * External dependencies
 */
import path from 'node:path';
import { defineConfig } from '@playwright/test';

/**
 * WordPress dependencies
 */
import baseConfig from '@wordpress/scripts/config/playwright.config';

process.env.WP_ARTIFACTS_PATH ??= path.join( process.cwd(), 'artifacts' );

/*
 * Where the authenticated session is kept.
 *
 * The storage state is a working admin session for the site under test: live
 * `wordpress_logged_in_*` cookies plus a REST nonce. The shared Playwright config
 * defaults it inside WP_ARTIFACTS_PATH, which is also the directory this suite
 * publishes its evidence from - the performance workflow uploads that directory
 * wholesale, on every run, including failed ones - so the default ships a usable
 * session next to the numbers it measured. Nothing outside this process reads the
 * file, so it is written among the other build caches, where it is ignored by git and
 * never collected, and the global teardown removes it once the workers are done.
 *
 * The shared config has already applied its own default by the time this runs, so that
 * value is replaced rather than defaulted. An explicitly chosen path is honoured.
 */
const artifactsStorageState = path.join(
	process.env.WP_ARTIFACTS_PATH,
	'storage-states/admin.json'
);

if (
	! process.env.STORAGE_STATE_PATH ||
	artifactsStorageState === process.env.STORAGE_STATE_PATH
) {
	process.env.STORAGE_STATE_PATH = path.join(
		process.cwd(),
		'.cache',
		'performance-storage-states',
		'admin.json'
	);
}

process.env.TEST_RUNS ??= '20';

const config = defineConfig( {
	...baseConfig,
	globalSetup: require.resolve( './config/global-setup.js' ),
	globalTeardown: require.resolve( './config/global-teardown.js' ),
	reporter: [ [ 'list' ], [ './config/performance-reporter.js' ] ],
	forbidOnly: !! process.env.CI,
	workers: 1,
	retries: 0,
	repeatEach: 2,
	timeout: parseInt( process.env.TIMEOUT || '', 10 ) || 600_000, // Defaults to 10 minutes.
	// Don't report slow test "files", as we will be running our tests in serial.
	reportSlowTests: null,
	preserveOutput: 'never',
	webServer: {
		...baseConfig.webServer,
		command: 'npm run env:start',
	},
	use: {
		...baseConfig.use,
		/*
		 * Set after the spread: the shared config captured the artifacts path above
		 * while it was being imported, so the relocation has to be applied here too.
		 */
		storageState: process.env.STORAGE_STATE_PATH,
		video: 'off',
	},
} );

export default config;
