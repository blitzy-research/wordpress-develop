/**
 * External dependencies
 */
import { rmSync } from 'node:fs';

/**
 * Removes the authenticated session the global setup wrote to disk.
 *
 * The storage state is a working admin session for the site under test - live
 * `wordpress_logged_in_*` cookies and a REST nonce - and it only has to exist while the
 * workers are reading it. Deleting it here keeps it from outliving the run that needed
 * it, so whatever collects the results afterwards has nothing to collect. The next run
 * authenticates again regardless: the global setup calls `setupRest()` unconditionally.
 *
 * Only the file is removed. The path is configurable, so removing its directory could
 * take something else with it.
 *
 * @param {import('@playwright/test').FullConfig} config Resolved Playwright configuration.
 * @return {void}
 */
function globalTeardown( config ) {
	const { storageState } = config.projects[ 0 ].use;

	if ( 'string' !== typeof storageState ) {
		return;
	}

	rmSync( storageState, { force: true } );
}

export default globalTeardown;
