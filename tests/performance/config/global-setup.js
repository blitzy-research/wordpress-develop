/**
 * External dependencies
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import { request } from '@playwright/test';

/**
 * WordPress dependencies
 */
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { activeThemeFromThemesPage, previousThemePath } from '../utils';

/**
 * Records which theme was active, so the global teardown can put it back.
 *
 * The suite activates a theme of its own, and every scenario after it - including a
 * hand-measured control taken later against the same installation - would otherwise be
 * measuring whatever the last run left behind rather than the theme it names. The slug is
 * kept on disk beside the cache reset token rather than in a module variable, because the
 * teardown is not guaranteed to run in the process that ran this.
 *
 * A failure to read or record it is not fatal: the run can still measure, and the
 * teardown treats an absent record as nothing to restore.
 *
 * @param {import('@playwright/test').APIRequestContext} requestContext Authenticated request context.
 * @param {string}                                       baseURL        Site under test.
 * @return {Promise<void>}
 */
async function recordActiveTheme( requestContext, baseURL ) {
	try {
		const response = await requestContext.get(
			new URL( 'wp-admin/themes.php', baseURL ).href
		);
		const slug = activeThemeFromThemesPage( await response.text() );

		await response.dispose();

		if ( '' === slug ) {
			return;
		}

		mkdirSync( dirname( previousThemePath() ), { recursive: true } );
		writeFileSync( previousThemePath(), slug, 'utf8' );
	} catch ( error ) {
		console.warn(
			`Could not record the active theme, so the global teardown will leave the suite's theme in place: ${ error.message }`
		);
	}
}

/**
 *
 * @param {import('@playwright/test').FullConfig} config
 * @returns {Promise<void>}
 */
async function globalSetup( config ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;

	const requestContext = await request.newContext( {
		baseURL,
	} );

	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath,
	} );

	// Authenticate and save the storageState to disk.
	await requestUtils.setupRest();

	await recordActiveTheme( requestContext, baseURL );

	// Reset the test environment before running the tests.
	await Promise.all( [ requestUtils.activateTheme( 'twentytwentyone' ) ] );

	await requestContext.dispose();
}

export default globalSetup;
