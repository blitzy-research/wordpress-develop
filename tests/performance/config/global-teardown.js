/**
 * External dependencies
 */
import { existsSync, readFileSync, rmSync } from 'node:fs';
import { request } from '@playwright/test';

/**
 * WordPress dependencies
 */
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { previousThemePath, revokeCacheResetToken } from '../utils';

/**
 * Puts back the theme that was active before the run.
 *
 * The suite activates a theme of its own to measure a known scenario, and leaving it
 * behind hands the next thing that looks at this installation - another suite, or someone
 * measuring a named theme by hand - a site that is no longer the one they think they are
 * measuring. The record is removed whether or not the activation succeeds, so a stale slug
 * cannot be applied by a later run, and a run that never recorded one leaves the theme
 * alone.
 *
 * A failure here is reported rather than raised: the measurements are already taken, and
 * failing the run over the cleanup would discard them.
 *
 * It authenticates for itself and is deliberately handed no storage state path.
 * `setupRest()` writes the session it establishes to any path it is given, and nothing
 * here reads one - the path is only read by `RequestUtils.setup()`, and this
 * reauthenticates regardless - so handing it the run's path would have this step write a
 * working admin session back to disk on its way out of the run that was finished with it.
 *
 * @param {import('@playwright/test').FullConfig} config Resolved Playwright configuration.
 * @return {Promise<void>}
 */
async function restorePreviousTheme( config ) {
	const path = previousThemePath();

	if ( ! existsSync( path ) ) {
		return;
	}

	let slug = '';

	try {
		slug = readFileSync( path, 'utf8' ).trim();
	} catch ( error ) {
		console.warn( `Could not read ${ path }: ${ error.message }` );
	}

	rmSync( path, { force: true } );

	if ( '' === slug ) {
		return;
	}

	const { baseURL } = config.projects[ 0 ].use;

	try {
		const requestContext = await request.newContext( { baseURL } );
		const requestUtils = new RequestUtils( requestContext );

		await requestUtils.setupRest();
		await requestUtils.activateTheme( slug );
		await requestContext.dispose();
	} catch ( error ) {
		console.warn(
			`Could not reactivate the ${ slug } theme, so it is still the suite's theme that is active: ${ error.message }`
		);
	}
}

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
 * measured run. It is revoked first, ahead of everything here that can fail, because it
 * is the one of the two that grants a state-changing operation.
 *
 * Only files are removed. Both paths are configurable, so removing a directory could
 * take something else with it.
 *
 * The theme the run found active is put back in between, and the session is removed after
 * it rather than before it. That order is the point: putting the theme back is the one
 * step that needs the site to answer, answering it means authenticating, and an
 * authentication is what writes a session to disk - so a removal that ran first is a
 * removal the step after it can undo. Removing it last, in a `finally`, keeps the
 * withdrawal as unconditional as the revocation above: a site that cannot answer the
 * restore leaves its theme as this run left it, never a session behind.
 *
 * @param {import('@playwright/test').FullConfig} config Resolved Playwright configuration.
 * @return {Promise<void>}
 */
async function globalTeardown( config ) {
	revokeCacheResetToken();

	const { storageState } = config.projects[ 0 ].use;

	try {
		await restorePreviousTheme( config );
	} finally {
		if ( 'string' === typeof storageState ) {
			rmSync( storageState, { force: true } );
		}
	}
}

export default globalTeardown;
