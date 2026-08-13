/**
 * External dependencies
 */
import { execFileSync } from 'node:child_process';
import { writeFileSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

let wpConfigOriginal;

/*
 * The table prefix the installation is exercised under.
 *
 * Named once because three separate things have to agree on it: the wp-config.php
 * rewrite that puts WordPress into new install mode, the tables a finished
 * installation leaves behind under that prefix, and the cleanup that releases them
 * again. They drifted apart when the prefix was written out at each site, which is
 * how the tables came to outlive the test that created them.
 */
const E2E_TABLE_PREFIX = 'wp_e2e_';

test.describe( 'WordPress installation process', () => {
	const wpConfig = join(
		process.cwd(),
		'wp-config.php',
	);

	/**
	 * Waits until the running site reports the install state this test needs.
	 *
	 * wp-config.php is compiled PHP like any other file, so rewriting it does not
	 * change what the next request sees until OPcache revalidates. The environment
	 * runs with `opcache.validate_timestamps=On` and `opcache.revalidate_freq=2`, so
	 * for up to two seconds after the write the site still boots with the previous
	 * table prefix. Navigating immediately after writing therefore reaches whichever
	 * prefix happened to still be cached, and the test asserts against the wrong
	 * site. That is deterministic rather than flaky: the write and the request fall
	 * inside the same revalidation window every time.
	 *
	 * `expect( page ).toHaveURL()` cannot absorb this on its own, because it re-reads
	 * the URL of a page that has already loaded rather than requesting it again. So
	 * the navigation itself is what retries here, which removes the race without a
	 * fixed sleep and stays correct on a slower host.
	 *
	 * @param {import('@playwright/test').Page} page      The page to navigate.
	 * @param {boolean}                         installed Whether the site is expected
	 *                                                    to report as installed.
	 */
	async function waitForInstallState( page, installed ) {
		await expect( async () => {
			await page.goto( '/' );

			if ( installed ) {
				expect( page.url() ).not.toMatch( /wp-admin\/install\.php/ );
			} else {
				expect( page.url() ).toMatch( /wp-admin\/install\.php$/ );
			}
		} ).toPass( { timeout: 30_000 } );
	}

	/**
	 * Drops every table carrying the prefix this test installs under.
	 *
	 * Switching the prefix only puts WordPress into new install mode while no table
	 * carries that prefix. As soon as an installation finishes, its options table
	 * holds a siteurl, WordPress reports the site as installed, `/` stops redirecting
	 * to install.php, and every later run of this test fails on its first assertion -
	 * permanently, until the tables are removed by hand. Releasing them is therefore
	 * part of the test, not housekeeping: it is called before the prefix is switched
	 * as well as after it is restored, so a run that crashed part way through cannot
	 * poison the next one.
	 *
	 * Both call sites run this while wp-config.php still carries the site's own
	 * prefix, so WordPress boots against the working installation. Booting it against
	 * the prefix under test would fail in exactly the case the cleanup matters most:
	 * a run that created some tables but never finished installing.
	 *
	 * `wp eval` is used rather than `wp db query` because the `wp db` subcommands
	 * shell out to the mysql client, which cannot reach the environment's MySQL 8.4
	 * server over its self-signed TLS chain, and because `tools/local-env/scripts/
	 * docker.js` appends a `--defaults` flag that only some `wp db` subcommands
	 * accept. `$wpdb` goes through mysqli, which is what the site itself uses.
	 *
	 * docker.js is invoked directly rather than through `npm run env:cli`, so that the
	 * PHP snippet reaches WP-CLI as one argument instead of being re-split by npm's
	 * argument forwarding and a shell.
	 */
	function dropTablesUnderTestPrefix() {
		const php =
			'global $wpdb;' +
			' foreach ( $wpdb->get_col( "SHOW TABLES" ) as $table ) {' +
			' if ( 0 === strpos( $table, "' + E2E_TABLE_PREFIX + '" ) ) {' +
			' $wpdb->query( "DROP TABLE IF EXISTS `" . $table . "`" );' +
			' } }';

		execFileSync(
			process.execPath,
			[
				join( process.cwd(), 'tools', 'local-env', 'scripts', 'docker.js' ),
				'exec',
				'--user',
				'wp_php',
				'cli',
				'wp',
				'eval',
				php,
				'--skip-themes',
				'--skip-plugins',
				`--path=/var/www/${ process.env.LOCAL_DIR || 'src' }`,
			],
			/*
			 * stdin is ignored so that docker.js sees a non-TTY and passes --no-TTY to
			 * `docker compose exec`, which it has to for this to run unattended.
			 */
			{ stdio: [ 'ignore', 'inherit', 'inherit' ] }
		);
	}

	test.beforeEach( async ( { page } ) => {
		wpConfigOriginal = readFileSync( wpConfig, 'utf-8' );

		dropTablesUnderTestPrefix();

		// Changing the table prefix tricks WP into new install mode.
		writeFileSync(
			wpConfig,
			wpConfigOriginal.replace( `$table_prefix = 'wp_';`, `$table_prefix = '${ E2E_TABLE_PREFIX }';` )
		);

		await waitForInstallState( page, false );
	} );

	test.afterEach( async ( { page } ) => {
		/*
		 * Restored before the tables are dropped, so that the drop runs against the
		 * site's own installation, and restored even if the drop then fails, so that a
		 * failure here cannot leave the rest of the suite pointed at the test prefix.
		 */
		writeFileSync( wpConfig, wpConfigOriginal );

		try {
			dropTablesUnderTestPrefix();
		} finally {
			await waitForInstallState( page, true );
		}
	} );

	test( 'should install WordPress with pre-existing database credentials', async ( { page } ) => {
		await page.goto( '/' );

		await expect(
			page,
			'should redirect to the installation page'
		).toHaveURL( /wp-admin\/install\.php$/ );

		await expect(
			page.getByText( /WordPress database error/ ),
			'should not have any database errors'
		).not.toBeVisible();

		/*
		 * First page: the language selector, when the installer offers one.
		 *
		 * install.php only shows it when a language pack can be installed and the
		 * translations API answered; when it cannot reach that API it drops straight
		 * to the next step, which its own comment describes as deliberate. Both
		 * shapes of the installer are therefore correct, and this test has to accept
		 * either - clicking the button unconditionally made a test about installing
		 * with pre-existing database credentials depend on api.wordpress.org being
		 * reachable from wherever it happens to run.
		 *
		 * Waiting for whichever step arrived first is what makes the check below
		 * reliable: isVisible() reports the current state without waiting, so it
		 * needs a loaded step to report on.
		 */
		const languageContinue = page.getByRole( 'button', { name: 'Continue' } );
		const welcomeHeading = page.getByRole( 'heading', { name: 'Welcome' } );

		await expect( languageContinue.or( welcomeHeading ).first() ).toBeVisible();

		if ( await languageContinue.isVisible() ) {
			// Keep the default, English (US).
			await languageContinue.click();
		}

		// Second page: enter site name, username & password.

		await expect( welcomeHeading ).toBeVisible();

		// This information matches tools/local-env/scripts/install.js.

		await page.getByLabel( 'Site Title' ).fill( 'WordPress Develop' );
		await page.getByLabel( 'Username' ).fill( 'admin' );
		await page.getByLabel( 'Password', { exact: true } ).fill( '' );
		await page.getByLabel( 'Password', { exact: true } ).fill( 'password' );
		await page.getByLabel( /Confirm use of weak password/ ).check()
		await page.getByLabel( 'Your Email' ).fill( 'test@example.com' );

		await page.getByRole( 'button', { name: 'Install WordPress' } ).click();

		// Installation finished, can now log in.

		await expect( page.getByRole( 'heading', { name: 'Success!' } ) ).toBeVisible();

		await page.getByRole( 'link', { name: 'Log In' } ).click();

		await expect(
			page,
			'should redirect to the login page'
		).toHaveURL( /wp-login\.php$/ );

		await page.getByLabel( 'Username or Email Address' ).fill( 'admin' );
		await page.getByLabel( 'Password', { exact: true } ).fill( 'password' );

		await page.getByRole( 'button', { name: 'Log In' } ).click();

		await expect(
			page.getByRole( 'heading', { name: 'Welcome to WordPress', level: 2 })
		).toBeVisible();
	} );
} );
