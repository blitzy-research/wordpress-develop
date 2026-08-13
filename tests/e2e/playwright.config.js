/**
 * External dependencies
 */
import path from 'node:path';
import { defineConfig } from '@playwright/test';

const dotenv = require( 'dotenv' );
const dotenvExpand = require( 'dotenv-expand' );

/*
 * The local environment's .env is what records which port this checkout serves on, and
 * the shared configuration resolves baseURL from WP_BASE_URL while it is being required,
 * falling back to http://localhost:8889. Expanded here, before that require, so a
 * checkout configured for another port runs its tests against its own site rather than
 * against whatever answers on 8889. Nothing already set in the environment is
 * overwritten, so an explicit WP_BASE_URL still wins.
 */
dotenvExpand.expand( dotenv.config() );

/**
 * WordPress dependencies
 */
const baseConfig = require( '@wordpress/scripts/config/playwright.config' );

process.env.WP_ARTIFACTS_PATH ??= path.join( process.cwd(), 'artifacts' );
process.env.STORAGE_STATE_PATH ??= path.join(
	process.env.WP_ARTIFACTS_PATH,
	'storage-states/admin.json'
);

const config = defineConfig( {
	...baseConfig,
	globalSetup: require.resolve( './config/global-setup.js' ),
	webServer: {
		...baseConfig.webServer,
		command: 'npm run env:start',
	},
} );

export default config;
