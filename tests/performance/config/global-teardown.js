/**
 * External dependencies
 */
import { rmSync } from 'node:fs';

/**
 * Internal dependencies
 */
import { revokeCacheResetToken } from '../utils';

/**
 * Removes the two secrets this run put on disk.
 *
 * The storage state is a working admin session for the site under test - live
 * `wordpress_logged_in_*` cookies and a REST nonce - and it only has to exist while the
 * workers are reading it. Deleting it here keeps it from outliving the run that needed
 * it, so whatever collects the results afterwards has nothing to collect. The next run
 * authenticates again regardless: the global setup calls `setupRest()` unconditionally.
 *
 * The cache reset token is what enables the mu-plugin's reset endpoint at all, so
 * withdrawing it here is what makes that endpoint exist for exactly the duration of one
 * measured run. It is revoked first and unconditionally, because it is the one of the
 * two that grants a state-changing operation, and because the storage state path is
 * configurable and may return early.
 *
 * Only files are removed. Both paths are configurable, so removing a directory could
 * take something else with it.
 *
 * @param {import('@playwright/test').FullConfig} config Resolved Playwright configuration.
 * @return {void}
 */
function globalTeardown( config ) {
	revokeCacheResetToken();

	const { storageState } = config.projects[ 0 ].use;

	if ( 'string' !== typeof storageState ) {
		return;
	}

	rmSync( storageState, { force: true } );
}

export default globalTeardown;
