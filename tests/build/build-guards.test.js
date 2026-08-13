/**
 * Task level guards for the two generated build artifacts.
 *
 * `build:autoload-classmap` and `verify:emoji-markers` are the only two build tasks whose
 * job is to refuse a result. Everything they protect against is silent by construction: an
 * autoload class map that is empty, truncated, stale or not the map the generator rendered
 * still looks like a legitimate build result, and `copy:files` would carry it into
 * `build/`; an emoji data file with no marker region, or with two, makes
 * `replace:emoji-regex` rewrite nothing or rewrite twice without complaining. The failure
 * modes therefore cannot be observed from the build's output -- only from the tasks
 * refusing -- so each refusal is exercised here.
 *
 * The generator's own logic is tested from PHP in
 * `tests/phpunit/tests/load/wpAutoloadClass.php`. What is tested here is the orchestration
 * around it: the checks the Grunt task performs on what the generator produced, the
 * generator's command line contract (its exit status, its digest line and the fact that a
 * second run rewrites nothing), and the marker gate.
 *
 * Every case runs the real tasks from the real `Gruntfile.js`, against a sandbox root
 * passed with `--base`. Nothing in the working tree is read or written by a case, so a
 * case cannot leave the tree modified however it ends, and the cases are free to build
 * source trees that could not exist in the working tree at all.
 *
 * Run with `grunt verify:build-guards`, or directly with `node --test tests/build/`.
 *
 * @package WordPress
 * @since 7.0.0
 */

const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const { spawnSync } = require( 'node:child_process' );
const crypto = require( 'node:crypto' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );

const REPO_DIR = path.resolve( __dirname, '..', '..' );
const GRUNT_BIN = path.join(
	REPO_DIR,
	'node_modules',
	'grunt',
	'bin',
	'grunt'
);
const GRUNTFILE = path.join( REPO_DIR, 'Gruntfile.js' );
const GENERATOR = path.join(
	REPO_DIR,
	'tools',
	'build',
	'generate-autoload-classmap.php'
);

const CLASSMAP_PATH = path.join(
	'src',
	'wp-includes',
	'autoload-classmap.php'
);
const EMOJI_PATH = path.join( 'src', 'wp-includes', 'emoji-arrays.php' );
const EMOJI_START = '// START: emoji arrays';
const EMOJI_END = '// END: emoji arrays';

/**
 * Files a sandbox borrows from the working tree.
 *
 * `Gruntfile.js` reads both JSHint configurations while it loads, so a sandbox has to
 * offer them. They are linked rather than copied so that a sandbox cannot drift from what
 * the build actually reads.
 *
 * @type {string[]}
 */
const BORROWED_PATHS = [
	'.jshintrc',
	path.join( 'tests', 'qunit', '.jshintrc' ),
];

/**
 * The manifest a sandbox presents to Grunt.
 *
 * Grunt refuses to run without a `package.json` under its base, and `Gruntfile.js` hands
 * the nearest one to `install-changed`, which runs `npm install` unless a `packagehash.txt`
 * beside it already records the dependencies it holds. A manifest that declares no
 * dependency at all, stamped as unchanged, is therefore the only shape that both satisfies
 * Grunt and leaves the sandbox alone: the real manifest would be seen as new and installed
 * from scratch on every case.
 *
 * @type {Object}
 */
const SANDBOX_MANIFEST = {
	name: 'wordpress-build-guards-sandbox',
	private: true,
	version: '1.0.0',
};

/**
 * A source tree the class map generator accepts, small enough to reason about entirely.
 *
 * `wp-settings.php` is where the generator starts walking, `wp-includes/autoload.php` is
 * where it reads the prefixes a mapped name must carry, the two `WP_` classes are the
 * entries it should render, and the class under `wp-includes/blocks/` is one it must leave
 * out because that directory is excluded.
 *
 * The two files under `wp-admin/includes/` are here because the generator opts them in by
 * name rather than by walking the bootstrap, and refuses to publish a map at all when one
 * of them cannot be read: a name it is expected to hold would otherwise be dropped
 * silently and become unresolvable at runtime. A tree that omits them is therefore a tree
 * the generator rejects, so the four entries below are the smallest set it accepts.
 *
 * @type {Object<string, string>}
 */
const SOURCE_TREE = {
	'src/wp-settings.php':
		"<?php\nrequire ABSPATH . WPINC . '/autoload.php';\n",
	'src/wp-includes/autoload.php':
		"<?php\n$core_prefixes = array( 'wp' );\nfunction wp_autoload_class( $name ) {\n\treturn;\n}\nspl_autoload_register( 'wp_autoload_class' );\n",
	'src/wp-includes/class-wp-alpha.php': '<?php\nclass WP_Alpha {}\n',
	'src/wp-includes/class-wp-beta.php': '<?php\nclass WP_Beta {}\n',
	'src/wp-includes/blocks/class-wp-excluded.php':
		'<?php\nclass WP_Excluded {}\n',
	'src/wp-admin/includes/class-wp-site-health.php':
		'<?php\nclass WP_Site_Health {}\n',
	'src/wp-admin/includes/class-wp-site-health-auto-updates.php':
		'<?php\nclass WP_Site_Health_Auto_Updates {}\n',
};

/**
 * Builds a sandbox root that the real Gruntfile can be pointed at.
 *
 * @param {Object<string, string>} files Paths relative to the root mapped to their contents.
 * @return {string} Absolute path of the sandbox root.
 */
function createSandbox( files = {} ) {
	const root = fs.realpathSync(
		fs.mkdtempSync( path.join( os.tmpdir(), 'wp-build-guards-' ) )
	);

	fs.mkdirSync( path.join( root, 'tests', 'qunit' ), { recursive: true } );
	fs.mkdirSync( path.join( root, 'tools', 'build' ), { recursive: true } );

	for ( const borrowed of BORROWED_PATHS ) {
		fs.symlinkSync(
			path.join( REPO_DIR, borrowed ),
			path.join( root, borrowed )
		);
	}

	writeSandboxFile(
		root,
		'package.json',
		`${ JSON.stringify( SANDBOX_MANIFEST, null, 2 ) }\n`
	);
	writeSandboxFile( root, 'packagehash.txt', dependencyStamp() );

	for ( const [ relative, contents ] of Object.entries( files ) ) {
		writeSandboxFile( root, relative, contents );
	}

	return root;
}

/**
 * Returns the stamp that marks the sandbox manifest's dependencies as already installed.
 *
 * Mirrors how `install-changed` digests a manifest: the MD5 of the two dependency objects,
 * in that order, as JSON. The sandbox manifest declares neither, so the stamp is a
 * constant. Every case also asserts that Grunt reported the manifest as unmodified, so a
 * change in that module is a named failure rather than an install running inside a test.
 *
 * @return {string} The digest to write to `packagehash.txt`.
 */
function dependencyStamp() {
	return crypto
		.createHash( 'md5' )
		.update(
			Buffer.from(
				JSON.stringify( {
					dependencies: SANDBOX_MANIFEST.dependencies ?? {},
					devDependencies: SANDBOX_MANIFEST.devDependencies ?? {},
				} )
			)
		)
		.digest( 'hex' );
}

/**
 * Writes a file inside a sandbox, creating the directories it needs.
 *
 * @param {string} root     Absolute path of the sandbox root.
 * @param {string} relative Path of the file relative to the root.
 * @param {string} contents Contents to write.
 * @return {string} Absolute path of the file written.
 */
function writeSandboxFile( root, relative, contents ) {
	const target = path.join( root, relative );

	fs.mkdirSync( path.dirname( target ), { recursive: true } );
	fs.writeFileSync( target, contents );

	return target;
}

/**
 * Installs the real class map generator into a sandbox.
 *
 * @param {string} root Absolute path of the sandbox root.
 */
function installRealGenerator( root ) {
	fs.symlinkSync(
		GENERATOR,
		path.join( root, 'tools', 'build', 'generate-autoload-classmap.php' )
	);
}

/**
 * Installs a generator that reports exactly what a case needs it to report.
 *
 * The Grunt task accepts the class map on disk only when the generator's last line says
 * that map is what it rendered. Every way that agreement can break is a separate refusal
 * in the task, and each one needs a generator that breaks it on purpose: one that fails,
 * one that succeeds without publishing, one that publishes something other than what it
 * announced. A stub is the only way to ask for those, because the real generator is
 * written not to produce any of them.
 *
 * @param {string} root     Absolute path of the sandbox root.
 * @param {Object} scenario What the stub should do.
 * @param {?string} scenario.write   Contents to publish, or null to publish nothing.
 * @param {boolean} scenario.remove  Whether to delete the class map before returning.
 * @param {string[]} scenario.stdout Lines to print on standard output.
 * @param {string[]} scenario.stderr Lines to print on standard error.
 * @param {number} scenario.exit     Status to exit with.
 */
function installStubGenerator( root, scenario ) {
	const settings = {
		write: null,
		remove: false,
		stdout: [],
		stderr: [],
		exit: 0,
		...scenario,
	};

	writeSandboxFile(
		root,
		path.join( 'tools', 'build', 'scenario.json' ),
		JSON.stringify( settings )
	);

	writeSandboxFile(
		root,
		path.join( 'tools', 'build', 'generate-autoload-classmap.php' ),
		`<?php
$scenario = json_decode( file_get_contents( __DIR__ . '/scenario.json' ), true );
$file     = ( isset( $argv[1] ) ? $argv[1] : 'src/' ) . 'wp-includes/autoload-classmap.php';

if ( null !== $scenario['write'] ) {
	if ( ! is_dir( dirname( $file ) ) ) {
		mkdir( dirname( $file ), 0777, true );
	}

	file_put_contents( $file, $scenario['write'] );
}

if ( $scenario['remove'] && file_exists( $file ) ) {
	unlink( $file );
}

foreach ( $scenario['stdout'] as $line ) {
	echo $line, PHP_EOL;
}

foreach ( $scenario['stderr'] as $line ) {
	fwrite( STDERR, $line . PHP_EOL );
}

exit( (int) $scenario['exit'] );
`
	);
}

/**
 * Renders the digest line the Grunt task looks for.
 *
 * @param {number} entries  Number of entries to announce.
 * @param {string} contents Contents to announce the length and hash of.
 * @return {string} The digest line.
 */
function digestLine( entries, contents ) {
	return `AUTOLOAD_CLASSMAP_DIGEST entries=${ entries } bytes=${ Buffer.byteLength(
		contents
	) } sha256=${ crypto
		.createHash( 'sha256' )
		.update( contents )
		.digest( 'hex' ) }`;
}

/**
 * Renders a class map file body with the entry lines the task counts.
 *
 * @param {Object<string, string>} entries Lower cased names mapped to their paths.
 * @param {string}                 tail    Text to close the file with.
 * @return {string} The file body.
 */
function classmapBody( entries, tail = ');\n' ) {
	const lines = Object.entries( entries ).map(
		( [ name, file ] ) => `\t'${ name }' => '${ file }',`
	);

	return `<?php\n\nreturn array(\n${ lines.join( '\n' ) }\n${ tail }`;
}

/**
 * Renders an emoji data file with a given number of generated regions.
 *
 * @param {number} regions How many marker regions the file should hold.
 * @return {string} The file body.
 */
function emojiDataFile( regions ) {
	let body = '<?php\n';

	for ( let index = 0; index < regions; index++ ) {
		body += `\t${ EMOJI_START }\n\t$entities = array( '&#x1f600;' );\n\t$partials = array( '&#x1f600;' );\n\t${ EMOJI_END }\n`;
	}

	if ( 0 === regions ) {
		body +=
			"\t$entities = array( '&#x1f600;' );\n\t$partials = array( '&#x1f600;' );\n";
	}

	return `${ body }\nreturn array(\n\t'entities' => $entities,\n\t'partials' => $partials,\n);\n`;
}

/**
 * Runs a Grunt task against a sandbox and returns what it reported.
 *
 * @param {string} root Absolute path of the sandbox root.
 * @param {string} task Task to run.
 * @return {{status: number, output: string}} Exit status and combined output.
 */
function runGruntTask( root, task ) {
	const result = spawnSync(
		process.execPath,
		[
			GRUNT_BIN,
			'--gruntfile',
			GRUNTFILE,
			'--base',
			root,
			'--no-color',
			task,
		],
		{
			cwd: root,
			encoding: 'utf8',
			maxBuffer: 32 * 1024 * 1024,
			stdio: [ 'ignore', 'pipe', 'pipe' ],
		}
	);

	assert.strictEqual(
		result.error,
		undefined,
		`Running \`grunt ${ task }\` must not fail to start: ${ result.error }`
	);

	const output = `${ result.stdout ?? '' }${ result.stderr ?? '' }`;

	assert.ok(
		output.includes( 'package.json has not been modified.' ),
		`The sandbox manifest must be recognised as already installed, or the case installs a dependency tree instead of running a task. Grunt reported:\n${ output }`
	);

	return { status: result.status, output };
}

/**
 * Runs the real class map generator against a sandbox and returns what it reported.
 *
 * @param {string} root Absolute path of the sandbox root.
 * @return {{status: number, stdout: string, stderr: string}} Exit status and output.
 */
function runGenerator( root ) {
	const result = spawnSync( 'php', [ GENERATOR, 'src/' ], {
		cwd: root,
		encoding: 'utf8',
		maxBuffer: 32 * 1024 * 1024,
	} );

	assert.strictEqual(
		result.error,
		undefined,
		`Running the class map generator must not fail to start: ${ result.error }`
	);

	return {
		status: result.status,
		stdout: result.stdout ?? '',
		stderr: result.stderr ?? '',
	};
}

/**
 * Asserts that a task refused, and refused for the stated reason.
 *
 * A task that fails for an unrelated reason satisfies an exit status check while proving
 * nothing about the guard under test, so the message is checked as well.
 *
 * @param {{status: number, output: string}} result What the task reported.
 * @param {string}                            reason Text the refusal must name.
 */
function assertRefused( result, reason ) {
	assert.notStrictEqual(
		result.status,
		0,
		`The task must fail. It reported:\n${ result.output }`
	);
	assert.ok(
		result.output.includes( reason ),
		`The refusal must say why. Expected it to mention ${ JSON.stringify(
			reason
		) }, and it reported:\n${ result.output }`
	);
}

test( 'the class map task accepts the map the generator published', ( t ) => {
	const root = createSandbox( SOURCE_TREE );
	t.after( () => fs.rmSync( root, { recursive: true, force: true } ) );
	installRealGenerator( root );

	const result = runGruntTask( root, 'build:autoload-classmap' );

	assert.strictEqual(
		result.status,
		0,
		`The task must accept a map it generated itself. It reported:\n${ result.output }`
	);
	assert.match(
		result.output,
		/Verified 4 autoload class map entries/,
		`The task must report the number of entries it verified. It reported:\n${ result.output }`
	);

	const published = fs.readFileSync(
		path.join( root, CLASSMAP_PATH ),
		'utf8'
	);

	assert.match(
		published,
		/^\t'wp_alpha' => 'wp-includes\/class-wp-alpha\.php',$/m,
		'The map must hold the classes the source tree declares.'
	);
	assert.match(
		published,
		/^\t'wp_beta' => 'wp-includes\/class-wp-beta\.php',$/m,
		'The map must hold the classes the source tree declares.'
	);
	assert.doesNotMatch(
		published,
		/wp_excluded/,
		'The map must leave out a class in an excluded directory.'
	);
} );

test( 'the class map task refuses a generator that failed', ( t ) => {
	const root = createSandbox( SOURCE_TREE );
	t.after( () => fs.rmSync( root, { recursive: true, force: true } ) );
	installStubGenerator( root, {
		stderr: [ 'Autoload class map generation failed: something is wrong.' ],
		exit: 1,
	} );

	assertRefused(
		runGruntTask( root, 'build:autoload-classmap' ),
		'The autoload class map generator failed'
	);
} );

test( 'the class map task refuses a run that published nothing', ( t ) => {
	const root = createSandbox( SOURCE_TREE );
	t.after( () => fs.rmSync( root, { recursive: true, force: true } ) );
	installStubGenerator( root, {
		stdout: [ 'Wrote 2 entries in src/wp-includes/autoload-classmap.php' ],
	} );

	assertRefused(
		runGruntTask( root, 'build:autoload-classmap' ),
		'reported no digest'
	);
} );

test( 'the class map task refuses an empty map', ( t ) => {
	const root = createSandbox( SOURCE_TREE );
	t.after( () => fs.rmSync( root, { recursive: true, force: true } ) );

	const body = '<?php\n\nreturn array();\n';

	installStubGenerator( root, {
		write: body,
		stdout: [ digestLine( 0, body ) ],
	} );

	assertRefused(
		runGruntTask( root, 'build:autoload-classmap' ),
		'No core classes were found'
	);
} );

test( 'the class map task refuses a map it cannot read back', ( t ) => {
	const root = createSandbox( SOURCE_TREE );
	t.after( () => fs.rmSync( root, { recursive: true, force: true } ) );

	const body = classmapBody( {
		wp_alpha: 'wp-includes/class-wp-alpha.php',
	} );

	installStubGenerator( root, {
		write: body,
		remove: true,
		stdout: [ digestLine( 1, body ) ],
	} );

	assertRefused(
		runGruntTask( root, 'build:autoload-classmap' ),
		'could not be read back'
	);
} );

test( 'the class map task refuses a map that is not the one announced', ( t ) => {
	const root = createSandbox( SOURCE_TREE );
	t.after( () => fs.rmSync( root, { recursive: true, force: true } ) );

	const published = classmapBody( {
		wp_alpha: 'wp-includes/class-wp-alpha.php',
	} );
	const announced = classmapBody( {
		wp_beta: 'wp-includes/class-wp-beta.php',
	} );

	installStubGenerator( root, {
		write: published,
		stdout: [ digestLine( 1, announced ) ],
	} );

	assertRefused(
		runGruntTask( root, 'build:autoload-classmap' ),
		'is not the map that was generated'
	);
} );

test( 'the class map task refuses a map holding fewer entries than announced', ( t ) => {
	const root = createSandbox( SOURCE_TREE );
	t.after( () => fs.rmSync( root, { recursive: true, force: true } ) );

	const body = classmapBody( {
		wp_alpha: 'wp-includes/class-wp-alpha.php',
	} );

	/*
	 * The digest matches the file byte for byte and announces one entry more than the
	 * file holds, so only the task's own count of the entry lines can catch it.
	 */
	installStubGenerator( root, {
		write: body,
		stdout: [ digestLine( 2, body ) ],
	} );

	assertRefused(
		runGruntTask( root, 'build:autoload-classmap' ),
		'holds 1 entries where the generator rendered 2'
	);
} );

test( 'the class map task refuses a map the PHP parser rejects', ( t ) => {
	const root = createSandbox( SOURCE_TREE );
	t.after( () => fs.rmSync( root, { recursive: true, force: true } ) );

	/*
	 * Closed with a stray token instead of the array closer, so the file is byte for
	 * byte what the digest announces and holds exactly the entry line the digest counts,
	 * and every check ahead of the lint passes.
	 */
	const body = classmapBody(
		{ wp_alpha: 'wp-includes/class-wp-alpha.php' },
		';\n'
	);

	installStubGenerator( root, {
		write: body,
		stdout: [ digestLine( 1, body ) ],
	} );

	assertRefused(
		runGruntTask( root, 'build:autoload-classmap' ),
		'is not valid PHP'
	);
} );

test( 'the class map generator rewrites nothing on a second run', ( t ) => {
	const root = createSandbox( SOURCE_TREE );
	t.after( () => fs.rmSync( root, { recursive: true, force: true } ) );

	const first = runGenerator( root );

	assert.strictEqual(
		first.status,
		0,
		`The generator must succeed on a tree it accepts: ${ first.stderr }`
	);
	assert.match(
		first.stdout,
		/^Wrote 4 entries in /m,
		`The first run must publish the map. It reported:\n${ first.stdout }`
	);

	const published = fs.readFileSync( path.join( root, CLASSMAP_PATH ) );
	const digest = /^AUTOLOAD_CLASSMAP_DIGEST .+$/m.exec( first.stdout )[ 0 ];
	const second = runGenerator( root );

	assert.strictEqual(
		second.status,
		0,
		`The generator must succeed on a second run: ${ second.stderr }`
	);
	assert.match(
		second.stdout,
		/^Verified 4 entries in /m,
		`A second run must report that it verified rather than wrote. It reported:\n${ second.stdout }`
	);
	assert.strictEqual(
		digest,
		/^AUTOLOAD_CLASSMAP_DIGEST .+$/m.exec( second.stdout )[ 0 ],
		'A second run must announce the same digest as the first.'
	);
	assert.ok(
		published.equals( fs.readFileSync( path.join( root, CLASSMAP_PATH ) ) ),
		'A second run must leave the map byte for byte as the first run left it, so that the drift guard in the workflows stays quiet.'
	);
} );

test( 'the class map generator refuses a name two files declare', ( t ) => {
	const root = createSandbox( {
		...SOURCE_TREE,
		'src/wp-includes/class-wp-alpha-again.php':
			'<?php\nclass WP_Alpha {}\n',
	} );
	t.after( () => fs.rmSync( root, { recursive: true, force: true } ) );

	const result = runGenerator( root );

	assert.notStrictEqual(
		result.status,
		0,
		`A duplicated name must stop generation. It reported:\n${ result.stdout }${ result.stderr }`
	);
	assert.match(
		result.stderr,
		/Duplicate class map name WP_Alpha, declared in both/,
		`The refusal must name the class and both files. It reported:\n${ result.stderr }`
	);
	assert.strictEqual(
		fs.existsSync( path.join( root, CLASSMAP_PATH ) ),
		false,
		'A run that stopped must publish nothing at all rather than a map missing a name.'
	);
} );

test( 'the marker gate accepts exactly one generated region', ( t ) => {
	const root = createSandbox( { [ EMOJI_PATH ]: emojiDataFile( 1 ) } );
	t.after( () => fs.rmSync( root, { recursive: true, force: true } ) );

	const result = runGruntTask( root, 'verify:emoji-markers' );

	assert.strictEqual(
		result.status,
		0,
		`One region must be accepted. The task reported:\n${ result.output }`
	);
} );

test( 'the marker gate refuses a file with no region', ( t ) => {
	const root = createSandbox( { [ EMOJI_PATH ]: emojiDataFile( 0 ) } );
	t.after( () => fs.rmSync( root, { recursive: true, force: true } ) );

	assertRefused(
		runGruntTask( root, 'verify:emoji-markers' ),
		'region in src/wp-includes/emoji-arrays.php, found 0'
	);
} );

test( 'the marker gate refuses a file with two regions', ( t ) => {
	const root = createSandbox( { [ EMOJI_PATH ]: emojiDataFile( 2 ) } );
	t.after( () => fs.rmSync( root, { recursive: true, force: true } ) );

	assertRefused(
		runGruntTask( root, 'verify:emoji-markers' ),
		'region in src/wp-includes/emoji-arrays.php, found 2'
	);
} );

test( 'the marker gate refuses a missing data file', ( t ) => {
	const root = createSandbox();
	t.after( () => fs.rmSync( root, { recursive: true, force: true } ) );

	assertRefused(
		runGruntTask( root, 'verify:emoji-markers' ),
		'The emoji data file is missing'
	);
} );

test( 'the shipped emoji data file carries exactly one generated region', () => {
	const shipped = fs.readFileSync(
		path.join( REPO_DIR, EMOJI_PATH ),
		'utf8'
	);
	const regions = shipped.match(
		new RegExp( `${ EMOJI_START }[\\S\\s]*?${ EMOJI_END }`, 'g' )
	);

	assert.strictEqual(
		null === regions ? 0 : regions.length,
		1,
		'`replace:emoji-regex` locates the arrays it regenerates by the markers, so the shipped data file must hold exactly one region.'
	);
} );
