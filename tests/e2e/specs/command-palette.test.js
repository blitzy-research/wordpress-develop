/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * The Command Palette is delivered by the `wp-commands` and `wp-core-commands` bundles,
 * which `wp_enqueue_command_palette_assets()` enqueues on 'admin_enqueue_scripts'.
 *
 * `wp_should_load_command_palette_assets()` is what decides whether a screen receives
 * them, and it delivers on the block editor screens and declines every other admin
 * screen. Both halves are load-bearing, so both are covered here: on the block editor the
 * palette has to keep working exactly as it did - the admin bar control, the keyboard
 * shortcut, the answers it gives to what is typed into it, and the JavaScript globals the
 * bundles define - and on an ordinary admin screen those same things have to be absent,
 * because that absence is the entire saving. Nothing else in the suites covers the
 * palette, so without these cases a change in either direction would go unnoticed: the
 * visual-regression suite masks `#wp-admin-bar-root-default`, which is where the control
 * lives.
 *
 * The editor screen used below is the post editor with fullscreen mode turned off, since
 * the editor hides the admin bar in fullscreen mode and the admin bar is where the control
 * is rendered. The preference is set through the editor's own store rather than by
 * clicking through the Options menu, so the case is testing the palette rather than that
 * menu.
 *
 * The filter that puts the palette back on every admin screen is covered without a browser,
 * in tests/phpunit/tests/dependencies/commandPalette.php, because it changes what is
 * enqueued rather than anything only a browser can observe.
 */
test.describe( 'Command Palette', () => {
	test( 'is available on a block editor screen', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost();

		// The globals the bundles define, which admin scripts and plugins read.
		await expect
			.poll( () => page.evaluate( () => typeof window.wp?.commands ) )
			.toBe( 'object' );

		expect(
			await page.evaluate( () => typeof window.wp?.coreCommands )
		).toBe( 'object' );

		/*
		 * The admin bar control, which is only rendered when the bundle behind it is
		 * enqueued. The editor hides the admin bar in fullscreen mode, so the preference
		 * is turned off first to make the control visible.
		 */
		await page.evaluate( () => {
			window.wp.data
				.dispatch( 'core/preferences' )
				.set( 'core', 'fullscreenMode', false );
		} );

		await expect(
			page.locator( '#wp-admin-bar-command-palette' )
		).toBeVisible();

		// The palette itself, opened the way the admin bar advertises it.
		await page.keyboard.press( 'ControlOrMeta+k' );

		const palette = page.getByRole( 'dialog', { name: 'Command palette' } );

		await expect( palette ).toBeVisible();

		// It answers what is typed into it rather than only rendering.
		await palette.getByRole( 'combobox' ).fill( 'dash' );

		await expect(
			palette.getByRole( 'option', { name: /Dashboard/i } ).first()
		).toBeVisible();

		await page.keyboard.press( 'Escape' );

		await expect( palette ).toBeHidden();
	} );

	test( 'is withheld from an ordinary admin screen', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( '/edit.php' );

		// The admin bar is rendered here, so a missing control is the gate and not the screen.
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

		await expect(
			page.locator( '#wp-admin-bar-command-palette' )
		).toHaveCount( 0 );

		expect( await page.evaluate( () => typeof window.wp?.commands ) ).toBe(
			'undefined'
		);

		expect(
			await page.evaluate( () => typeof window.wp?.coreCommands )
		).toBe( 'undefined' );

		await page.keyboard.press( 'ControlOrMeta+k' );

		await expect(
			page.getByRole( 'dialog', { name: 'Command palette' } )
		).toHaveCount( 0 );
	} );
} );
