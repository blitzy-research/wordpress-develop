/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * The Command Palette is a global admin capability: the admin bar advertises Ctrl+K on
 * every screen that has the `wp-core-commands` bundle, and that bundle is enqueued by
 * `wp_enqueue_command_palette_assets()` on 'admin_enqueue_scripts'.
 *
 * `wp_should_load_command_palette_assets()` is what decides whether a screen receives it.
 * The predicate exists so a site can stop paying for the bundles, and it defaults to
 * delivering them, so these cases assert the capability an ordinary admin screen actually
 * has: the control is in the admin bar, the keyboard shortcut opens the palette, the
 * palette answers what is typed into it, and the JavaScript globals the bundles define are
 * present. Nothing else in the suites covers the palette, so a change that withheld it
 * from a screen that had it would otherwise go unnoticed - the visual-regression suite
 * masks `#wp-admin-bar-root-default`, which is where the control lives.
 */
test.describe( 'Command Palette', () => {
	test( 'is available on an ordinary admin screen', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( '/' );

		// The admin bar control, which is only rendered when the bundle behind it is enqueued.
		const paletteButton = page.locator( '#wp-admin-bar-command-palette' );

		await expect( paletteButton ).toBeVisible();

		// The globals the bundles define, which admin scripts and plugins read.
		await expect
			.poll( () => page.evaluate( () => typeof window.wp?.commands ) )
			.toBe( 'object' );

		expect(
			await page.evaluate( () => typeof window.wp?.coreCommands )
		).toBe( 'object' );

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

	test( 'is available on a list table screen', async ( { admin, page } ) => {
		await admin.visitAdminPage( '/edit.php' );

		await expect(
			page.locator( '#wp-admin-bar-command-palette' )
		).toBeVisible();

		await page.keyboard.press( 'ControlOrMeta+k' );

		await expect(
			page.getByRole( 'dialog', { name: 'Command palette' } )
		).toBeVisible();
	} );
} );
