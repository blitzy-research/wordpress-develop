import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const elementsToHide = [
	'#footer-upgrade',
	'#wp-admin-bar-root-default',
	'#toplevel_page_gutenberg'
];

test.describe( 'Admin Visual Snapshots', () => {
	test( 'All Posts', async ({ admin, page }) => {
		await admin.visitAdminPage( '/edit.php' );
		await expect( page ).toHaveScreenshot( 'All Posts.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Categories', async ({ admin, page }) => {
		await admin.visitAdminPage( '/edit-tags.php', 'taxonomy=category' );
		await expect( page ).toHaveScreenshot( 'Categories.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Tags', async ({ admin, page }) => {
		await admin.visitAdminPage( '/edit-tags.php', 'taxonomy=post_tag' );
		await expect( page ).toHaveScreenshot( 'Tags.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Media Library', async ({ admin, page }) => {
		await admin.visitAdminPage( '/upload.php' );
		await expect( page ).toHaveScreenshot( 'Media Library.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Add Media', async ({ admin, page }) => {
		await admin.visitAdminPage( '/media-new.php' );
		await expect( page ).toHaveScreenshot( 'Add Media.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'All Pages', async ({ admin, page }) => {
		await admin.visitAdminPage( '/edit.php', 'post_type=page' );
		await expect( page ).toHaveScreenshot( 'All Pages.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Comments', async ({ admin, page }) => {
		await admin.visitAdminPage( '/edit-comments.php' );
		await expect( page ).toHaveScreenshot( 'Comments.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Widgets', async ({ admin, page }) => {
		await admin.visitAdminPage( '/widgets.php' );
		await expect( page ).toHaveScreenshot( 'Widgets.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Menus', async ({ admin, page }) => {
		await admin.visitAdminPage( '/nav-menus.php' );
		await expect( page ).toHaveScreenshot( 'Menus.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Plugins', async ({ admin, page }) => {
		await admin.visitAdminPage( '/plugins.php' );
		await expect( page ).toHaveScreenshot( 'Plugins.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'All Users', async ({ admin, page }) => {
		await admin.visitAdminPage( '/users.php' );
		await expect( page ).toHaveScreenshot( 'All Users.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Add User', async ({ admin, page }) => {
		await admin.visitAdminPage( '/user-new.php' );
		await expect( page ).toHaveScreenshot( 'Add User.png', {
			mask: [
					...elementsToHide,
					'.password-input-wrapper'
			].map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Your Profile', async ({ admin, page }) => {
		await admin.visitAdminPage( '/profile.php' );
		await expect( page ).toHaveScreenshot( 'Your Profile.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Available Tools', async ({ admin, page }) => {
		await admin.visitAdminPage( '/tools.php' );
		await expect( page ).toHaveScreenshot( 'Available Tools.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Import', async ({ admin, page }) => {
		await admin.visitAdminPage( '/import.php' );
		await expect( page ).toHaveScreenshot( 'Import.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Export', async ({ admin, page }) => {
		await admin.visitAdminPage( '/export.php' );
		await expect( page ).toHaveScreenshot( 'Export.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Export Personal Data', async ({ admin, page }) => {
		await admin.visitAdminPage( '/export-personal-data.php' );
		await expect( page ).toHaveScreenshot( 'Export Personal Data.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Erase Personal Data', async ({ admin, page }) => {
		await admin.visitAdminPage( '/erase-personal-data.php' );
		await expect( page ).toHaveScreenshot( 'Erase Personal Data.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Reading Settings', async ({ admin, page }) => {
		await admin.visitAdminPage( '/options-reading.php' );
		await expect( page ).toHaveScreenshot( 'Reading Settings.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Discussion Settings', async ({ admin, page }) => {
		await admin.visitAdminPage( '/options-discussion.php' );
		await expect( page ).toHaveScreenshot( 'Discussion Settings.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Media Settings', async ({ admin, page }) => {
		await admin.visitAdminPage( '/options-media.php' );
		await expect( page ).toHaveScreenshot( 'Media Settings.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	test( 'Privacy Settings', async ({ admin, page }) => {
		await admin.visitAdminPage( '/options-privacy.php' );
		await expect( page ).toHaveScreenshot( 'Privacy Settings.png', {
			mask: elementsToHide.map( ( selector ) => page.locator( selector ) ),
		});
	} );

	/*
	 * The cases above list `#wp-admin-bar-root-default` in `elementsToHide`, which reads
	 * as though the subtree the Command Palette control lives in were masked out of them.
	 * It is not: that `ul` carries only floated children, so it measures 1280x0 and the
	 * mask Playwright paints over it has no area. The only mask visible in those snapshots
	 * is `#footer-upgrade`. So every one of them does capture the control, and each of
	 * their baselines is specific to whether the screen it was taken on receives it.
	 *
	 * This case captures the control on its own, so that expectation is stated rather than
	 * left implicit in 22 full-page images.
	 *
	 * Which screens receive it is deliberate: `wp_should_load_command_palette_assets()`
	 * enqueues the palette on the block editor screens and declines it elsewhere, and
	 * `wp_admin_bar_command_palette_menu()` renders the button only where the bundle
	 * behind it was enqueued. So the control belongs in the editor and does not belong on
	 * the Dashboard, and both halves are asserted here: the screenshot fails if the
	 * control stops rendering where it should, and the count fails if it returns to a
	 * screen where it should not. The second half is what a full-page baseline cannot
	 * state on its own, because a baseline records only what one screen looked like on the
	 * day it was written.
	 */
	test( 'Admin Bar Command Palette control', async ({ admin, page }) => {
		// createNewPost() leaves fullscreen mode off, so the admin bar is visible here.
		await admin.createNewPost();
		await expect(
			page.locator( '#wp-admin-bar-command-palette' )
		).toHaveScreenshot( 'Admin Bar Command Palette control.png' );

		await admin.visitAdminPage( '/' );
		await expect(
			page.locator( '#wp-admin-bar-command-palette' )
		).toHaveCount( 0 );
	} );

	/*
	 * No other case visits a block editor screen, so nothing above covers the editor's own
	 * chrome. The header is the part of it that is the same on every install: the canvas
	 * below it renders whatever the fixture holds, and its caret, block toolbar and
	 * autosave indicator move between two screenshots of the same code.
	 */
	test( 'Post Editor header', async ({ admin, page }) => {
		await admin.createNewPost();
		await expect(
			page.locator( '.editor-header' )
		).toHaveScreenshot( 'Post Editor header.png' );
	} );
} );
