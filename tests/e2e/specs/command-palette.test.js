/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/*
 * End-to-end coverage for the Command Palette delivery gate.
 *
 * The PHP suite in tests/phpunit/tests/dependencies/commandPalette.php already
 * proves the server-side contract of wp_should_load_command_palette_assets()
 * and wp_enqueue_command_palette_assets(): which handles are registered, which
 * are enqueued, what the inline initializer contains, and how the
 * `should_load_command_palette_assets` filter changes each of those.
 *
 * What no PHP test can prove is that the payload the gate lets through actually
 * produces a working Command Palette in a browser, and that nothing
 * palette-related reaches the screens the gate excludes. That is what this spec
 * covers, and it does so against the real DOM rather than against a snapshot:
 *
 * - the palette opens from the keyboard shortcut on a block editor screen,
 * - the palette opens from the admin bar item on block editor screens,
 * - a command chosen in the palette runs,
 * - no asset, admin bar item, or keyboard response exists on admin screens that
 *   are not block editor screens,
 * - nothing palette-related reaches the front end, logged in or anonymous.
 */

/*
 * Selectors, limited to markup whose shape core itself controls.
 *
 * - `.commands-command-menu*` are the class names @wordpress/commands renders.
 * - `[cmdk-input]` and `[cmdk-item]` are the stable data attributes of the
 *   underlying command menu primitives. `[cmdk-item]`, `[role="option"]` and
 *   `.commands-command-menu__item` always select the same set of rows.
 * - `#wp-admin-bar-command-palette` is printed by
 *   wp_admin_bar_command_palette_menu().
 * - `#wp-core-commands-js-after` is the id WP_Scripts gives the inline
 *   initializer that wp_enqueue_command_palette_assets() attaches to the
 *   `wp-core-commands` handle.
 *
 * The rest of the palette markup is unusable as a locator. The `radix-*` ids,
 * and the `aria-controls` and `aria-labelledby` values that reference them, are
 * regenerated on every open, and the item rows carry generated class names. A
 * bare `[role="dialog"]` is unsafe too, because the widgets screen ships a
 * hidden `#wp-link-wrap` dialog that would match it.
 */
const PALETTE = '.commands-command-menu';
const PALETTE_OVERLAY = '.commands-command-menu__overlay';
const PALETTE_INPUT = '.commands-command-menu input[cmdk-input]';
const PALETTE_ITEM = '.commands-command-menu [cmdk-item]';
const ADMIN_BAR_ITEM = '#wp-admin-bar-command-palette';
const INLINE_INITIALIZER = 'script#wp-core-commands-js-after';

/**
 * Reports whether the `core/commands` data store is registered on a page.
 *
 * This is the one signal that is indifferent to how the bundles were
 * transported, so it holds whether scripts are served individually or
 * concatenated through load-scripts.php.
 *
 * The lookup has to be defensive rather than walking the chain directly: on
 * screens the gate excludes, `window.wp` still exists because wp-hooks and
 * wp-i18n create it, while `window.wp.data` does not.
 *
 * @param {import('@playwright/test').Page} page Page to inspect.
 *
 * @return {Promise<boolean>} Whether the commands store is available.
 */
async function hasCommandsStore( page ) {
	return page.evaluate( () => {
		try {
			return !! (
				window.wp &&
				window.wp.data &&
				window.wp.data.select( 'core/commands' )
			);
		} catch ( error ) {
			return false;
		}
	} );
}

/**
 * Asserts that every browser-observable trace of the Command Palette is present.
 *
 * @param {import('@playwright/test').Page} page Page to inspect.
 */
async function expectPaletteDelivered( page ) {
	const initializer = page.locator( INLINE_INITIALIZER );
	await expect( initializer ).toHaveCount( 1 );

	const initializerSource = await initializer.innerHTML();
	expect( initializerSource ).toContain(
		'wp.coreCommands.initializeCommandPalette('
	);
	expect( initializerSource ).toContain( 'is_network_admin' );

	await expect( page.locator( ADMIN_BAR_ITEM ) ).toHaveCount( 1 );

	// Polled, because the bundles are still arriving as the document settles.
	await expect.poll( () => hasCommandsStore( page ) ).toBe( true );
}

/**
 * Asserts that nothing palette-related was delivered to a page.
 *
 * @param {import('@playwright/test').Page} page Page to inspect.
 */
async function expectPaletteAbsent( page ) {
	await expect( page.locator( INLINE_INITIALIZER ) ).toHaveCount( 0 );
	await expect( page.locator( ADMIN_BAR_ITEM ) ).toHaveCount( 0 );
	await expect( page.locator( PALETTE ) ).toHaveCount( 0 );

	/*
	 * Substring checks against the served document rather than against
	 * `script[src]` and `link[href]`, so that the assertion holds under script
	 * concatenation as well: when load-scripts.php serves the bundles, the
	 * `wp-core-commands` handle appears in its query string instead of a file
	 * name, and either form contains `core-commands`.
	 */
	const html = await page.content();
	expect( html ).not.toContain( 'core-commands' );
	expect( html ).not.toContain( 'initializeCommandPalette' );

	expect( await hasCommandsStore( page ) ).toBe( false );
}

test.describe( 'Command Palette', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'opens with the keyboard shortcut on a block editor screen', async ( {
		admin,
		editor,
		page,
		pageUtils,
	} ) => {
		await admin.createNewPost();

		await expectPaletteDelivered( page );

		// Nothing is rendered until the palette is opened.
		await expect( page.locator( PALETTE ) ).toHaveCount( 0 );

		/*
		 * Place the caret in the title, leaving the selection collapsed. The
		 * core/link format registers a competing shortcut on the same key, but
		 * only while a rich text selection is expanded, so a collapsed caret
		 * leaves the shortcut to the palette.
		 */
		await editor.canvas
			.getByRole( 'textbox', { name: 'Add title' } )
			.click();

		await pageUtils.pressKeys( 'primary+k' );

		const palette = page.locator( PALETTE );
		await expect( palette ).toBeVisible();

		// The palette exposes itself as a labelled dialog, and takes focus.
		await expect( palette ).toHaveAttribute( 'role', 'dialog' );
		await expect(
			page.getByRole( 'dialog', { name: 'Command palette' } )
		).toBeVisible();
		await expect( page.locator( PALETTE_INPUT ) ).toBeFocused();

		// Escape unmounts the dialog and its overlay rather than hiding them.
		await page.keyboard.press( 'Escape' );
		await expect( palette ).toHaveCount( 0 );
		await expect( page.locator( PALETTE_OVERLAY ) ).toHaveCount( 0 );
	} );

	test( 'opens from the admin bar item on the post editor screen', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost();

		const adminBarItem = page.locator( ADMIN_BAR_ITEM );
		await expect( adminBarItem ).toBeVisible();

		/*
		 * Assert the accessible label rather than the shortcut hint. The hint
		 * is printed as a Command key legend for Apple user agents and as a
		 * Control key legend for everything else, while the screen reader text
		 * reads the same either way.
		 */
		await expect( adminBarItem ).toContainText( 'Open command palette' );
		await expect( adminBarItem.locator( 'kbd' ) ).not.toBeEmpty();

		const urlBeforeOpening = page.url();

		await adminBarItem.getByRole( 'menuitem' ).click();

		const palette = page.locator( PALETTE );
		await expect( palette ).toBeVisible();
		await expect( page.locator( PALETTE_INPUT ) ).toBeFocused();

		// The item is an in-page control, so activating it must not navigate.
		expect( page.url() ).toBe( urlBeforeOpening );

		await page.keyboard.press( 'Escape' );
		await expect( palette ).toHaveCount( 0 );
	} );

	test( 'opens from the admin bar item on the widgets screen', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.visitAdminPage( 'widgets.php' );

		// A first visit otherwise opens a welcome modal that takes focus.
		await editor.setPreferences( 'core/edit-widgets', {
			welcomeGuide: false,
		} );
		await expect(
			page.locator( '.edit-widgets-welcome-guide' )
		).toHaveCount( 0 );

		await expectPaletteDelivered( page );

		const adminBarItem = page.locator( ADMIN_BAR_ITEM );
		await expect( adminBarItem ).toBeVisible();

		await adminBarItem.getByRole( 'menuitem' ).click();

		const palette = page.locator( PALETTE );
		await expect( palette ).toBeVisible();
		await expect( page.locator( PALETTE_INPUT ) ).toBeFocused();

		await page.keyboard.press( 'Escape' );
		await expect( palette ).toHaveCount( 0 );
	} );

	test( 'runs a command chosen from the palette', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost();

		await page.locator( ADMIN_BAR_ITEM ).getByRole( 'menuitem' ).click();

		const input = page.locator( PALETTE_INPUT );
		await expect( input ).toBeFocused();

		await input.pressSequentially( 'Settings' );

		// The menu commands are built from the payload the gate printed inline.
		await expect( page.locator( PALETTE_ITEM ).first() ).toBeVisible();

		const generalSettings = page.locator(
			`${ PALETTE_ITEM }[data-value*="Settings > General"]`
		);
		await expect( generalSettings ).toHaveCount( 1 );

		await generalSettings.click();

		// Running the command navigates, which is the observable outcome.
		await page.waitForURL( /\/wp-admin\/options-general\.php/ );
		await expect(
			page.getByRole( 'heading', { name: 'General Settings', level: 1 } )
		).toBeVisible();

		// The destination is not a block editor screen, so the gate closes.
		await expectPaletteAbsent( page );
	} );

	const nonBlockEditorScreens = [
		{ name: 'Dashboard', path: 'index.php' },
		{ name: 'Posts list', path: 'edit.php' },
		{ name: 'General Settings', path: 'options-general.php' },
		{ name: 'Media Library', path: 'upload.php' },
	];

	for ( const screen of nonBlockEditorScreens ) {
		test( `is not delivered on the ${ screen.name } screen`, async ( {
			admin,
			page,
		} ) => {
			await admin.visitAdminPage( screen.path );

			/*
			 * The admin bar itself is rendered on these screens, so a missing
			 * palette item is the gate declining to enqueue rather than an
			 * admin bar that never ran.
			 */
			await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

			await expectPaletteAbsent( page );
		} );
	}

	test( 'does not answer the keyboard shortcut where it is not delivered', async ( {
		admin,
		page,
		pageUtils,
	} ) => {
		await admin.visitAdminPage( 'index.php' );

		await expectPaletteAbsent( page );

		const dialogsBefore = await page.locator( '[role="dialog"]' ).count();

		await page
			.getByRole( 'heading', { name: 'Dashboard', level: 1 } )
			.click();
		await pageUtils.pressKeys( 'primary+k' );

		/*
		 * Proving that a keypress produced nothing needs a settle: an
		 * immediate count of zero would be satisfied just as well by a palette
		 * that had simply not mounted yet.
		 */
		await page.waitForTimeout( 1000 );

		await expect( page.locator( PALETTE ) ).toHaveCount( 0 );
		await expect( page.locator( PALETTE_OVERLAY ) ).toHaveCount( 0 );
		await expect( page.locator( PALETTE_INPUT ) ).toHaveCount( 0 );
		expect( await page.locator( '[role="dialog"]' ).count() ).toBe(
			dialogsBefore
		);
	} );

	test( 'is not delivered on the front end for a logged in user', async ( {
		page,
	} ) => {
		await page.goto( '/' );

		/*
		 * The admin bar is rendered for logged in visitors, and it carries a
		 * search item whose magnifier resembles a palette affordance. Asserting
		 * on the palette id keeps that item from being mistaken for one.
		 */
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

		await expectPaletteAbsent( page );
	} );

	test( 'is not delivered on the front end for anonymous visitors', async ( {
		browser,
		baseURL,
	} ) => {
		const anonymousContext = await browser.newContext( {
			baseURL,
			storageState: { cookies: [], origins: [] },
		} );

		try {
			const anonymousPage = await anonymousContext.newPage();
			await anonymousPage.goto( '/' );

			// An absent admin bar proves the request really was unauthenticated.
			await expect(
				anonymousPage.locator( '#wpadminbar' )
			).toHaveCount( 0 );

			await expectPaletteAbsent( anonymousPage );
		} finally {
			await anonymousContext.close();
		}
	} );
} );
