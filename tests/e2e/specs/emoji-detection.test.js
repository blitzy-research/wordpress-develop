/**
 * External dependencies
 */
import { existsSync, unlinkSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Emoji detection', () => {
	const documentRoot = join( process.cwd(), process.env.LOCAL_DIR ?? 'src' );
	const optInPlugin = join(
		documentRoot,
		'wp-content/mu-plugins/e2e-emoji-detection-gate.php'
	);

	test.beforeAll( async () => {
		writeFileSync(
			optInPlugin,
			`<?php
if ( isset( $_GET['enable_emoji_detection'] ) ) {
	add_filter( 'should_load_emoji_detection_script', '__return_true' );
}
`
		);
	} );

	test.afterAll( async () => {
		if ( existsSync( optInPlugin ) ) {
			unlinkSync( optInPlugin );
		}
	} );

	test( 'is not printed by default', async ( { page } ) => {
		await page.goto( '/' );

		await expect( page.locator( '#wp-emoji-settings' ) ).toHaveCount( 0 );
		await expect
			.poll( () =>
				page.evaluate(
					() => typeof window._wpemojiSettings === 'undefined'
				)
			)
			.toBe( true );
	} );

	test( 'loads the fallback when a site opts in and browser or operating-system support is incomplete', async ( {
		page,
	} ) => {
		await page.addInitScript( () => {
			window.sessionStorage.setItem(
				'wpEmojiSettingsSupports',
				JSON.stringify( {
					timestamp: Date.now(),
					supportTests: {
						flag: false,
						emoji: false,
					},
				} )
			);
		} );

		await page.goto( '/?enable_emoji_detection=1' );

		await expect( page.locator( '#wp-emoji-settings' ) ).toHaveCount( 1 );
		await page.waitForFunction( () => {
			return window._wpemojiSettings?.supports?.everything === false;
		} );

		const support = await page.evaluate(
			() => window._wpemojiSettings.supports
		);
		expect( support ).toMatchObject( {
			everything: false,
			flag: false,
			emoji: false,
		} );

		await page.waitForFunction( () => {
			const source = window._wpemojiSettings?.source ?? {};
			const loadedScripts = Array.from(
				document.querySelectorAll( 'script[src]' ),
				( script ) => script.src
			);

			if ( source.concatemoji ) {
				return loadedScripts.includes( source.concatemoji );
			}

			return (
				loadedScripts.includes( source.twemoji ) &&
				loadedScripts.includes( source.wpemoji )
			);
		} );
	} );
} );
