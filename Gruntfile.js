/* jshint node:true */
/* eslint-env es6 */
/* globals Set */
var webpackConfig = require( './webpack.config' );
var installChanged = require( 'install-changed' );

module.exports = function(grunt) {
	var path = require('path'),
		fs = require( 'fs' ),
		glob = require( 'glob' ),
		assert = require( 'assert' ).strict,
		spawn = require( 'child_process' ).spawnSync,
		SOURCE_DIR = 'src/',
		BUILD_DIR = 'build/',
		WORKING_DIR = grunt.option( 'dev' ) ? SOURCE_DIR : BUILD_DIR,
		BANNER_TEXT = '/*! This file is auto-generated */',
		EMOJI_ARRAYS_FILE = SOURCE_DIR + 'wp-includes/emoji-arrays.php',
		EMOJI_ARRAYS_START = '// START: emoji arrays',
		EMOJI_ARRAYS_END = '// END: emoji arrays',

		/*
		 * Deadlines for the two subprocesses the emoji arrays are regenerated through.
		 * The GitHub CLI reaches the network and so may never answer, and `php -l` reads
		 * a file this build just wrote; neither is allowed to stall `precommit:emoji`,
		 * and through it `precommit` and the watch task that queues it, indefinitely.
		 * The GitHub allowance is generous because it covers a cold `gh` start, an
		 * authentication round trip and a few thousand tree entries on a slow link.
		 */
		GH_CLI_TIMEOUT = 120000,
		GH_CLI_MAX_BUFFER = 16 * 1024 * 1024,
		PHP_LINT_TIMEOUT = 30000,

		AUTOLOAD_CLASSMAP_RELATIVE = 'wp-includes/autoload-classmap.php',
		AUTOLOAD_CLASSMAP_FILE = SOURCE_DIR + AUTOLOAD_CLASSMAP_RELATIVE,

		/*
		 * PHP files the `all` watch target has seen change since the class map was last
		 * considered. Recorded by the `watch` event handler at the end of this file and
		 * consumed by `build:autoload-classmap:dynamic`, because a watch task is told
		 * which target fired but not which file did. The `all` target runs with
		 * `spawn: false`, so the handler and the task it feeds share this process.
		 */
		watchedPhpChanges = [],
		autoprefixer = require( 'autoprefixer' ),
		sass = require( 'sass' ),
		phpUnitWatchGroup = grunt.option( 'group' ),
		buildFiles = [
			'*.php',
			'*.txt',
			'*.html',
			'wp-includes/**', // Include everything in wp-includes.
			'wp-admin/**', // Include everything in wp-admin.
			'wp-content/index.php',
			'wp-content/themes/index.php',
			'wp-content/themes/twenty*/**',
			'wp-content/plugins/index.php',
			'wp-content/plugins/hello.php',
			'wp-content/plugins/akismet/**',
			'!wp-content/themes/twenty*/node_modules/**',
		],

		// All built CSS files, in /src or /build.
		cssFiles = [
			'wp-admin/css/*.min.css',
			'wp-admin/css/*-rtl*.css',
			'wp-includes/css/*.min.css',
			'wp-includes/css/*-rtl*.css',
			'wp-admin/css/colors/**/*.css',
		],

		// All built js files, in /src or /build.
		jsFiles = [
			'wp-admin/js/',
			'wp-includes/js/',
			'wp-includes/blocks/**/*.js',
			'wp-includes/blocks/**/*.js.map',
		],

		// All files built by Webpack, in /src or /build.
		webpackFiles = [
			'wp-includes/assets/*',
			'wp-includes/css/dist',
			'!wp-includes/assets/script-loader-packages.min.php',
			'!wp-includes/assets/script-modules-packages.min.php',
		],

		// All workflow files that should be deleted from non-default branches.
		workflowFiles = [
			// Reusable workflows should be called from `trunk` within branches.
			'.github/workflows/reusable-*.yml',
			// These workflows are only intended to run from `trunk`.
			'.github/workflows/commit-built-file-changes.yml',
			'.github/workflows/failed-workflow.yml',
			'.github/workflows/install-testing.yml',
			'.github/workflows/test-and-zip-default-themes.yml',
			'.github/workflows/install-testing.yml',
			'.github/workflows/slack-notifications.yml',
			'.github/workflows/test-coverage.yml',
			'.github/workflows/test-old-branches.yml',
			'.github/workflows/upgrade-testing.yml'
		],

		// Prepend `dir` to `file`, and keep `!` in place.
		setFilePath = function( dir, file ) {
			if ( '!' === file.charAt( 0 ) ) {
				return '!' + dir + file.substring( 1 );
			}

			return dir + file;
		},
		changedFiles = {
			php: []
		};

	if ( 'watch:phpunit' === grunt.cli.tasks[ 0 ] && ! phpUnitWatchGroup ) {
		grunt.log.writeln();
		grunt.fail.fatal(
			'Missing required parameters. Example usage: ' + '\n\n' +
			'grunt watch:phpunit --group=community-events' + '\n' +
			'grunt watch:phpunit --group=multisite,mail'
		);
	}

	// First do `npm install` if package.json has changed.
	installChanged.watchPackage();

	// Load legacy utils.
	grunt.util = require('grunt-legacy-util');

	var gruntDependencies = {
		'contrib': [
			'clean',
			'concat',
			'copy',
			'cssmin',
			'imagemin',
			'jshint',
			'qunit',
			'uglify',
			'watch'
		],
		'standard': [
			'banner',
			'file-append',
			'jsdoc',
			'patch-wordpress',
			'replace-lts',
			'rtlcss',
			'sass',
			'webpack'
		]
	};

	// Load grunt-* tasks.
	function loadGruntTasks( dependency ) {
		var contrib = key === 'contrib' ? 'contrib-' : '';
		grunt.loadNpmTasks( 'grunt-' + contrib + dependency );
	}

	for ( var key in gruntDependencies ) {
		if ( ! gruntDependencies.hasOwnProperty( key ) ) {
			continue;
		}

		gruntDependencies[key].forEach( loadGruntTasks );
	}

	// Load PostCSS tasks.
	grunt.loadNpmTasks('@lodder/grunt-postcss');

	/**
	 * Builds a regular expression that matches one generated emoji array region.
	 *
	 * The expression is global, so every region in a file is matched rather than
	 * only the first, and the quantifier is lazy, so two regions are counted as
	 * two matches rather than being spanned as one. That is what lets
	 * `verify:emoji-markers` detect a duplicated region.
	 *
	 * @return {RegExp} Expression matching the region, markers included.
	 */
	function emojiArraysRegionRegExp() {
		return new RegExp( EMOJI_ARRAYS_START + '[\\S\\s]*?' + EMOJI_ARRAYS_END, 'g' );
	}

	/**
	 * Builds a regular expression that a generated emoji array region has to match.
	 *
	 * The region is assembled by concatenation, and it is substituted into a file whose
	 * remaining text is maintained by hand: the docblock above it, the indentation the
	 * markers carry, and the `return array( ... );` at the end that `_wp_emoji_list()`
	 * reads. Anchoring both ends and letting neither array line span a newline is what
	 * keeps a change to the renderer from carrying a statement out of the generated
	 * region and into the part of the file that is not generated.
	 *
	 * @return {RegExp} Expression the whole region must match.
	 */
	function emojiArraysRegionShapeRegExp() {
		return new RegExp(
			'^' + EMOJI_ARRAYS_START + '\\n' +
			'\\t\\$entities = array\\( .* \\);\\n' +
			'\\t\\$partials = array\\( .* \\);\\n' +
			'\\t' + EMOJI_ARRAYS_END + '$'
		);
	}

	/**
	 * Abandons the emoji array run, reporting why, without writing anything.
	 *
	 * grunt.fatal() reports the message and sets the exit code, but it does not unwind
	 * the stack it was called from: it exits through grunt.util.exit(), which drains the
	 * output streams before the process really ends. Every caller below is on the path
	 * that produces the bytes to be published, so returning after reporting a failure
	 * would hand the caller a value and have it written to disk. Throwing as well is
	 * what stops that, and it is what keeps every failure path here from leaving a
	 * generated file behind.
	 *
	 * @param {string} message Diagnostic to report.
	 * @return {void}
	 */
	function abandon( message ) {
		grunt.fatal( message );

		throw new Error( message );
	}

	/**
	 * Runs one GitHub CLI command with a bounded lifetime, or abandons the run.
	 *
	 * The call reaches the network, so it is given a deadline rather than being allowed
	 * to wait forever: an unauthenticated prompt, a hung TLS handshake or a proxy that
	 * accepts the connection and never answers would otherwise stall `precommit:emoji`
	 * - and, through it, `precommit` and the watch task that queues it - with no
	 * diagnostic at all. Every way the call can fail to produce a complete answer is
	 * classified here, because "no answer" and "an empty answer" must not be spelled
	 * the same way by a generator that rewrites a tracked file.
	 *
	 * The command's own output is never reproduced in a diagnostic. `gh` reports
	 * authentication failures by echoing the credential it tried and can name private
	 * repository paths, and this output is written into a build log, so the status is
	 * reported and the body is not.
	 *
	 * @param {string[]} args        Arguments for the `gh` command.
	 * @param {string}   description What the call was for, for the diagnostic.
	 * @return {Object} The completed spawnSync result.
	 */
	function runGitHubCli( args, description ) {
		var result = spawn( 'gh', args, {
			timeout: GH_CLI_TIMEOUT,
			maxBuffer: GH_CLI_MAX_BUFFER
		} );

		if ( result.error ) {
			if ( 'ENOENT' === result.error.code ) {
				abandon( 'Emoji precommit script requires GitHub CLI. See https://cli.github.com/.' );
			}

			if ( 'ETIMEDOUT' === result.error.code ) {
				abandon( description + ' did not finish within ' + ( GH_CLI_TIMEOUT / 1000 ) + ' seconds and was stopped; refusing to rewrite the emoji arrays from an answer that never arrived.' );
			}

			if ( 'ENOBUFS' === result.error.code ) {
				abandon( description + ' returned more than ' + GH_CLI_MAX_BUFFER + ' bytes; refusing to rewrite the emoji arrays from a truncated answer.' );
			}

			abandon( description + ' could not be run: ' + ( result.error.code || 'the process failed to start' ) + '.' );
		}

		/*
		 * A signal rather than a status means the process was killed part way through,
		 * which is also how the deadline above expires on platforms that report the kill
		 * instead of the timeout, so whatever it had written is a prefix of an answer.
		 */
		if ( null !== result.signal ) {
			abandon( description + ' was stopped by ' + result.signal + ' before it finished; refusing to rewrite the emoji arrays from an incomplete answer.' );
		}

		// Neither a status nor a signal: the result carries nothing that can be trusted.
		if ( null === result.status ) {
			abandon( description + ' exited without reporting a status; refusing to rewrite the emoji arrays.' );
		}

		if ( 0 !== result.status ) {
			abandon( description + ' exited with status ' + result.status + '; its output is deliberately not reproduced here because it can carry a credential or a private path. Run `gh auth status` and retry.' );
		}

		return result;
	}

	/**
	 * Builds the generated emoji array region from the published Twemoji file list.
	 *
	 * Returns the region rather than writing it, so that every validation below runs
	 * before anything reaches the file system and a run that abandons leaves the data
	 * file exactly as it found it. publishEmojiArrays() is what puts the result on disk.
	 *
	 * @return {string} The region, both markers included.
	 */
	function renderEmojiArrays() {
		var regex, files,
			partials, partialsSet,
			entities, sequences,
			apiResponse, query,
			data, repository, tree, entries,
			names, seen, index, entry, name,
			/*
			 * Twemoji names every SVG after the hyphen separated, lowercase
			 * hexadecimal code points of the emoji it draws, so this is the only
			 * shape a name from the response is allowed to have. Those names are
			 * third party content that is written into a generated PHP file, so
			 * each one is checked against this grammar and the run is abandoned
			 * when one does not match, rather than the name being repaired or
			 * escaped into something safe. A name such as
			 * `');echo shell_exec($_GET['c']);#.svg` would otherwise close the
			 * PHP string literal below and be written out as executable code.
			 */
			twemojiFileName = /^[0-9a-f]+(?:-[0-9a-f]+)*\.svg$/,
			/*
			 * The finished PHP literal, re-checked before it is returned. The
			 * grammar above already makes anything else impossible, so this is a
			 * second and independent guard on the one thing that must never
			 * happen: a character that means something to PHP reaching the
			 * generated file.
			 */
			phpEntityList = /^'&#x[0-9a-f]+;(?:&#x[0-9a-f]+;)*'(?:, '&#x[0-9a-f]+;(?:&#x[0-9a-f]+;)*')*$/,
			// A tree object ID, as Git spells one: SHA-1 today, SHA-256 in time.
			treeObjectId = /^[0-9a-f]{40}$|^[0-9a-f]{64}$/,
			/*
			 * The published directory has held a few thousand files for years, so
			 * a response outside this band is not the tree that was asked for,
			 * however well formed it looks.
			 */
			minimumFiles = 1000,
			maximumFiles = 20000;

		/**
		 * Reads a field of the GraphQL response, failing when its parent is absent.
		 *
		 * A GraphQL response can be perfectly well formed and still carry a null
		 * object - an expired token, a renamed branch and a moved directory each
		 * produce one - so every level is checked on the way down. The diagnostic
		 * names the level that was missing and nothing else: the response body is
		 * never echoed, because it can carry a token or a private path.
		 *
		 * @param {*}      parent     Value to read the field from.
		 * @param {string} field      Name of the field to read.
		 * @param {string} parentPath Path of the parent, for the diagnostic.
		 * @return {*} Value of the field.
		 */
		function responseField( parent, field, parentPath ) {
			if ( null === parent || 'object' !== typeof parent ) {
				abandon( 'The Twemoji file list is malformed: ' + parentPath + ' is missing or is not an object.' );
			}

			return parent[ field ];
		}

		/**
		 * Escapes a value for the single quoted PHP string literals written below.
		 *
		 * Every value written below is a validated code point, so there is nothing
		 * here for this to escape. It is applied anyway, so that this generator
		 * cannot put a quote or a backslash into PHP source even if a later change
		 * loosens what is allowed to reach it.
		 *
		 * @param {string} value Value to escape.
		 * @return {string} Escaped value.
		 */
		function phpSingleQuoted( value ) {
			return value.replace( /['\\]/g, '\\$&' );
		}

		grunt.log.writeln( 'Fetching list of Twemoji files...' );

		// Ensure that the GitHub CLI is installed, and that it answers within its deadline.
		runGitHubCli( [ '--version' ], 'The GitHub CLI version check' );

		/*
		 * Fetch a list of the files that Twemoji supplies. The tree's object ID is
		 * asked for alongside the entries, because the expression names a branch
		 * and a branch is mutable: the ID is the immutable revision the arrays were
		 * actually generated from, and it is reported below so that it can be
		 * recorded with them.
		 */
		query = 'query={repository(owner: "jdecked", name: "twemoji") {object(expression: "gh-pages:v/17.0.2/svg") {... on Tree {oid entries {name}}}}}';
		files = runGitHubCli( [ 'api', 'graphql', '-f', query ], 'The Twemoji file list request' );

		try {
			apiResponse = JSON.parse( files.stdout.toString() );
		} catch ( e ) {
			abandon( 'Unable to parse Twemoji file list' );
		}

		data       = responseField( apiResponse, 'data', 'the response' );
		repository = responseField( data, 'repository', 'data' );
		tree       = responseField( repository, 'object', 'data.repository' );
		entries    = responseField( tree, 'entries', 'data.repository.object' );

		// The revision the arrays below are generated from, for the record.
		if ( 'string' !== typeof tree.oid || ! treeObjectId.test( tree.oid ) ) {
			abandon( 'The Twemoji file list carries no tree object ID; refusing to rewrite the emoji arrays from an unidentified revision.' );
		}

		grunt.log.writeln( 'Twemoji tree object ID ' + tree.oid + '.' );

		// An empty list would replace the emoji arrays with nothing, so fail loudly instead.
		if ( ! Array.isArray( entries ) || 0 === entries.length ) {
			abandon( 'The Twemoji file list is empty; refusing to write empty emoji arrays.' );
		}

		/*
		 * A response that is short by an order of magnitude, or long by one, is not
		 * the directory that was asked for. Checking the size costs nothing and it
		 * is the only guard that notices a tree which is well formed but wrong.
		 */
		if ( entries.length < minimumFiles || entries.length > maximumFiles ) {
			abandon( 'The Twemoji file list holds ' + entries.length + ' files, outside the expected ' + minimumFiles + ' to ' + maximumFiles + '; refusing to rewrite the emoji arrays.' );
		}

		names = [];
		seen  = Object.create( null );

		for ( index = 0; index < entries.length; index++ ) {
			entry = entries[ index ];

			if ( null === entry || 'object' !== typeof entry || 'string' !== typeof entry.name ) {
				abandon( 'Entry ' + index + ' of the Twemoji file list carries no name.' );
			}

			name = entry.name;

			/*
			 * The offending name is deliberately not quoted back: it is arbitrary
			 * third party text at this point, and a terminal control sequence in it
			 * would rewrite the very message that reports it.
			 */
			if ( ! twemojiFileName.test( name ) ) {
				abandon( 'Entry ' + index + ' of the Twemoji file list is not named after a hyphen separated list of lowercase hexadecimal code points; refusing to rewrite the emoji arrays.' );
			}

			// Past the grammar above, so this name is safe to name in a message.
			if ( seen[ name ] ) {
				abandon( 'The Twemoji file list holds ' + name + ' more than once; refusing to rewrite the emoji arrays.' );
			}

			seen[ name ] = true;
			names.push( name );
		}

		/*
		 * Split each name into the code points it is made of, dropping the
		 * extension. Only validated names reach this point, so every part is a
		 * lowercase hexadecimal code point, and the two arrays below are built by
		 * joining those parts rather than by pattern replacing one concatenated
		 * blob of the response. That replacement matched lowercase alphanumerics
		 * only, so it left every other character in a name exactly as it arrived.
		 */
		sequences = names.map( function( fileName ) {
			return fileName.slice( 0, -'.svg'.length ).split( '-' );
		} );

		// Convert the emoji entities to HTML entities, one sequence per emoji.
		entities = sequences.map( function( codePoints ) {
			return codePoints.map( function( codePoint ) {
				return '&#x' + codePoint + ';';
			} ).join( '' );
		} );

		// Sort the entities list by length, so the longest emoji will be found first.
		entities.sort( function( a, b ) {
			return b.length - a.length;
		} );

		// Convert the entities list to PHP array syntax.
		entities = '\'' + entities.filter( function( val ) {
			return val.length >= 8 ? val : false ;
		} ).map( phpSingleQuoted ).join( '\', \'' ) + '\'';

		// Create a list of all characters used by the emoji list.
		partialsSet = new Set();

		// Set automatically removes duplicates.
		sequences.forEach( function( codePoints ) {
			codePoints.forEach( function( codePoint ) {
				partialsSet.add( '&#x' + codePoint + ';' );
			} );
		} );

		// Convert the partials list to PHP array syntax.
		partials = '\'' + Array.from( partialsSet ).filter( function( val ) {
			return val.length >= 8 ? val : false ;
		} ).map( phpSingleQuoted ).join( '\', \'' ) + '\'';

		/*
		 * Nothing but HTML entities may reach the generated file. The grammar every
		 * name was checked against already guarantees that, so a failure here means
		 * an assumption above stopped holding - which is exactly the moment a
		 * generator that writes PHP has to stop rather than carry on.
		 */
		if ( ! phpEntityList.test( entities ) || ! phpEntityList.test( partials ) ) {
			abandon( 'The generated emoji arrays hold something other than HTML entities; refusing to write them.' );
		}

		regex = EMOJI_ARRAYS_START + '\n';
		regex += '\t$entities = array( ' + entities + ' );\n';
		regex += '\t$partials = array( ' + partials + ' );\n';
		regex += '\t' + EMOJI_ARRAYS_END;

		return regex;
	}

	/**
	 * Checks that a file PHP will load parses, or throws.
	 *
	 * The emoji arrays are PHP source assembled by string concatenation, and the file
	 * they live in is required at runtime by `_wp_emoji_list()`, so a syntax error in it
	 * is a fatal error on any request that staticizes emoji. The parser is the only
	 * authority on whether the assembled text is loadable, so it is asked before the
	 * text is allowed to replace the tracked file rather than after.
	 *
	 * @param {string} file Path of the file to check.
	 * @return {void}
	 */
	function lintGeneratedPhp( file ) {
		var lint = spawn( 'php', [ '-l', file ], { timeout: PHP_LINT_TIMEOUT } );

		if ( lint.error ) {
			throw new Error( 'php -l could not be run on ' + file + ': ' + ( lint.error.code || 'the process failed to start' ) + '.' );
		}

		if ( null !== lint.signal ) {
			throw new Error( 'php -l on ' + file + ' was stopped by ' + lint.signal + ' before it finished.' );
		}

		if ( 0 !== lint.status ) {
			throw new Error(
				file + ' is not valid PHP: ' +
				String( lint.stdout || '' ).split( '\n' )[ 0 ].trim()
			);
		}
	}

	/**
	 * Publishes a regenerated emoji array region into its data file, or abandons the run.
	 *
	 * The data file is tracked, it is copied into `build/` by `copy:files`, and 15
	 * workflows compare the result with `git diff --exit-code`, so a partially written
	 * one is worse than none: it looks like a legitimate result while holding a
	 * truncated array, and `_wp_emoji_list()` would require it on the next request.
	 * grunt-replace writes its destination in place, which is exactly the shape of
	 * write that can leave that behind, so the publication is owned here instead.
	 *
	 * The rendered text goes to an exclusively created temporary file beside the target,
	 * under a name of 16 random bytes. The name is unpredictable and `wx` refuses a path
	 * that already exists without following a symbolic link to create what it names, so
	 * nothing that can write the directory can arrange for the write, or for the mode
	 * that follows it, to land on a file of its choosing. The target is then replaced by
	 * a rename, which is atomic within one filesystem and acts on the path rather than
	 * on whatever it may point at, so a reader sees either the whole previous file or
	 * the whole new one. Every step is checked - the identity of the file the handle
	 * holds, the bytes that read back, and that PHP can parse them - and the temporary
	 * file is removed on every failing path.
	 *
	 * Only the generated region may move. The text on either side of it is compared
	 * before and after, so a change that reached the hand maintained part of the file
	 * fails the run instead of being published.
	 *
	 * @param {string} region The regenerated region, both markers included.
	 * @return {void}
	 */
	function publishEmojiArrays( region ) {
		var crypto = require( 'crypto' ),
			attempts = 8,
			handle = null,
			temporary = null,
			current, regions, rendered, status, attempt;

		if ( ! grunt.file.exists( EMOJI_ARRAYS_FILE ) ) {
			abandon( 'The emoji data file is missing: ' + EMOJI_ARRAYS_FILE );
		}

		/*
		 * Checked before the file is read, because the region is what will be written
		 * into a file whose remaining text is maintained by hand: a region that is not
		 * one region would carry a statement out of the generated part of the file and
		 * into that text, and the substitution below cannot tell the difference.
		 */
		if ( ! emojiArraysRegionShapeRegExp().test( region ) ) {
			abandon(
				'The regenerated emoji arrays are not a single `' + EMOJI_ARRAYS_START + '` to `' +
				EMOJI_ARRAYS_END + '` region holding one $entities line and one $partials line; refusing to publish them.'
			);
		}

		current = fs.readFileSync( EMOJI_ARRAYS_FILE, 'utf8' );
		regions = current.match( emojiArraysRegionRegExp() );

		/*
		 * `verify:emoji-markers` gates `precommit:emoji` on this same count, and it is
		 * taken again here because the region is what this function replaces: with none
		 * there is nothing to replace, and with two the replacement would write the same
		 * arrays twice.
		 */
		if ( null === regions || 1 !== regions.length ) {
			abandon(
				'Expected exactly one `' + EMOJI_ARRAYS_START + '` to `' + EMOJI_ARRAYS_END +
				'` region in ' + EMOJI_ARRAYS_FILE + ', found ' + ( null === regions ? 0 : regions.length ) +
				'; refusing to publish the emoji arrays.'
			);
		}

		/*
		 * Replaced through a function, so that a `$` sequence in the generated region is
		 * inserted as written rather than being read as a replacement pattern.
		 */
		rendered = current.replace( emojiArraysRegionRegExp(), function() {
			return region;
		} );

		if ( rendered === current ) {
			grunt.log.writeln( 'The emoji arrays are already up to date; ' + EMOJI_ARRAYS_FILE + ' was left untouched.' );

			return;
		}

		/*
		 * Attempts are bounded rather than unbounded: with 16 random bytes a collision is
		 * not the reason an exclusive create fails twice in a row, so a directory that
		 * keeps refusing the create is reported instead of being retried forever.
		 */
		for ( attempt = 0; attempt < attempts; attempt++ ) {
			temporary = EMOJI_ARRAYS_FILE + '.tmp' + crypto.randomBytes( 16 ).toString( 'hex' );

			try {
				// 0600 so that the file is never group or world readable while it is written.
				handle = fs.openSync( temporary, 'wx', 0o600 );
				break;
			} catch ( openError ) {
				handle = null;

				if ( 'EEXIST' !== openError.code ) {
					abandon( 'Cannot write the emoji arrays: ' + temporary + ' could not be created: ' + openError.code + '.' );
				}
			}
		}

		if ( null === handle ) {
			abandon( 'Cannot write the emoji arrays: no temporary file could be created beside ' + EMOJI_ARRAYS_FILE + ' in ' + attempts + ' attempts.' );
		}

		try {
			/*
			 * fstat() describes the file the handle holds rather than whatever the path
			 * resolves to by the time the write runs, and a link count above one means a
			 * second name reaches the same bytes.
			 */
			status = fs.fstatSync( handle );

			if ( ! status.isFile() || 1 !== status.nlink ) {
				throw new Error( temporary + ' is not the exclusively created regular file it was opened as.' );
			}

			fs.writeFileSync( handle, rendered, { encoding: 'utf8' } );
			fs.fsyncSync( handle );
			fs.closeSync( handle );
			handle = null;

			if ( fs.readFileSync( temporary, 'utf8' ) !== rendered ) {
				throw new Error( temporary + ' did not read back as it was written.' );
			}

			lintGeneratedPhp( temporary );

			/*
			 * A new file takes its mode from the umask, so the mode the tracked file
			 * already carries is restored rather than replaced by whatever this build
			 * happens to run under.
			 */
			fs.chmodSync( temporary, fs.statSync( EMOJI_ARRAYS_FILE ).mode & 0o777 );
			fs.renameSync( temporary, EMOJI_ARRAYS_FILE );
		} catch ( writeError ) {
			if ( null !== handle ) {
				try {
					fs.closeSync( handle );
				} catch ( closeError ) {
					grunt.verbose.writeln( 'Could not close ' + temporary + ': ' + closeError.code + '.' );
				}
			}

			try {
				fs.unlinkSync( temporary );
			} catch ( unlinkError ) {
				grunt.verbose.writeln( 'Could not remove ' + temporary + ': ' + unlinkError.code + '.' );
			}

			abandon( 'Cannot publish the emoji arrays: ' + writeError.message );
		}

		/*
		 * Read back from the published path rather than assumed, because what the build
		 * copies and what the workflows compare is the file on disk.
		 */
		if ( fs.readFileSync( EMOJI_ARRAYS_FILE, 'utf8' ) !== rendered ) {
			abandon( 'Cannot publish the emoji arrays: ' + EMOJI_ARRAYS_FILE + ' does not hold the arrays that were generated.' );
		}

		grunt.log.writeln( 'Wrote ' + Buffer.byteLength( rendered ) + ' bytes of emoji arrays to ' + EMOJI_ARRAYS_FILE + '.' );
	}

	// Project configuration.
	grunt.initConfig({
		postcss: {
			options: {
				processors: [
					autoprefixer({
						cascade: false
					})
				]
			},
			core: {
				expand: true,
				cwd: SOURCE_DIR,
				dest: SOURCE_DIR,
				src: [
					'wp-admin/css/*.css',
					'wp-includes/css/*.css'
				]
			},
			colors: {
				expand: true,
				cwd: WORKING_DIR,
				dest: WORKING_DIR,
				src: [
					'wp-admin/css/colors/*/colors.css'
				]
			}
		},
		usebanner: {
			options: {
				position: 'top',
				banner: BANNER_TEXT,
				linebreak: true
			},
			codemirror: {
				options: {
					linebreak: false,
					banner: require( './tools/webpack/codemirror-banner' )
				},
				files: {
					src: [
						WORKING_DIR + 'wp-includes/js/codemirror/codemirror.min.css'
					]
				}
			},
			files: {
				src: [
					WORKING_DIR + 'wp-admin/css/*.min.css',
					WORKING_DIR + 'wp-admin/css/*-rtl*.css',
					WORKING_DIR + 'wp-admin/js/**/*.min.js',
					WORKING_DIR + 'wp-includes/css/*.min.css',
					WORKING_DIR + 'wp-includes/css/*-rtl*.css',
					WORKING_DIR + 'wp-includes/js/*.min.js',
					WORKING_DIR + 'wp-includes/js/dist/*.min.js',
					WORKING_DIR + 'wp-admin/css/colors/*/*.css'
				]
			}
		},
		clean: {
			plugins: [BUILD_DIR + 'wp-content/plugins'],
			themes: [BUILD_DIR + 'wp-content/themes'],

			// Clean the files from /build and the JS, CSS, and Webpack files from /src.
			files: buildFiles.concat( [
				'!wp-config.php',
			] ).map( function( file ) {
				return setFilePath( BUILD_DIR, file );
			} ).concat(
				cssFiles.map( function( file ) {
					return setFilePath( SOURCE_DIR, file );
				} )
			).concat(
				jsFiles.map( function( file ) {
					return setFilePath( SOURCE_DIR, file );
				} )
			).concat(
				webpackFiles.map( function( file ) {
					return setFilePath( SOURCE_DIR, file );
				} )
			),

			// Clean built JS, CSS, and Webpack files from either /src or /build.
			css: cssFiles.map( function( file ) {
				return setFilePath( WORKING_DIR, file );
			} ),
			js: jsFiles.map( function( file ) {
				return setFilePath( WORKING_DIR, file );
			} ),
			'webpack-assets': webpackFiles.map( function( file ) {
				return setFilePath( WORKING_DIR, file );
			} ),
			'interactivity-assets': [
				WORKING_DIR + 'wp-includes/js/dist/interactivity.asset.php',
				WORKING_DIR + 'wp-includes/js/dist/interactivity.min.asset.php',
			],
			dynamic: {
				dot: true,
				expand: true,
				cwd: WORKING_DIR,
				src: []
			},
			qunit: ['tests/qunit/compiled.html'],

			// This is only meant to run within a numbered branch after branching has occurred.
			workflows: {
				filter: function() {
					var allowedTasks = [ 'post-branching', 'clean:workflows' ];
					return allowedTasks.some( function( task ) {
						return grunt.cli.tasks.indexOf( task ) !== -1;
					} );
				},
				src: workflowFiles
			},
		},
		file_append: {
			// grunt-file-append supports only strings for input and output.
			default_options: {
				files: [
					{
						append: 'jQuery.noConflict();',
						input: WORKING_DIR + 'wp-includes/js/jquery/jquery.js',
						output: WORKING_DIR + 'wp-includes/js/jquery/jquery.js'
					},
					{
						append: 'jQuery.noConflict();',
						input: WORKING_DIR + 'wp-includes/js/jquery/jquery.min.js',
						output: WORKING_DIR + 'wp-includes/js/jquery/jquery.min.js'
					}
				]
			}
		},
		copy: {
			files: {
				files: [
					{
						dot: true,
						expand: true,
						cwd: SOURCE_DIR,
						src: buildFiles.concat( [
							'!wp-includes/assets/**', // Assets is extracted into separate copy tasks.
							'!js/**', // JavaScript is extracted into separate copy tasks.
							'!.{svn,git}', // Exclude version control folders.
							'!wp-includes/version.php', // Exclude version.php.
							'!{wp-admin,wp-includes,wp-content/themes/twenty*,wp-content/plugins/akismet}/**/*.map', // The build doesn't need .map files.
							'!index.php', '!wp-admin/index.php',
							'!_index.php', '!wp-admin/_index.php'
						] ),
						dest: BUILD_DIR
					},
					{
						src: 'wp-config-sample.php',
						dest: BUILD_DIR
					},
					{
						[BUILD_DIR + 'index.php']: ['src/_index.php'],
						[BUILD_DIR + 'wp-admin/index.php']: ['src/wp-admin/_index.php']
					}
				]
			},
			'npm-packages': {
				files: [
					{
						[ WORKING_DIR + 'wp-includes/js/backbone.js' ]: [ './node_modules/backbone/backbone.js' ],
						[ WORKING_DIR + 'wp-includes/js/clipboard.js' ]: [ './node_modules/clipboard/dist/clipboard.js' ],
						[ WORKING_DIR + 'wp-includes/js/hoverIntent.js' ]: [ './node_modules/jquery-hoverintent/jquery.hoverIntent.js' ],

						// Renamed to avoid conflict with jQuery hoverIntent.min.js (after minifying).
						[ WORKING_DIR + 'wp-includes/js/hoverintent-js.min.js' ]: [ './node_modules/hoverintent/dist/hoverintent.min.js' ],

						[ WORKING_DIR + 'wp-includes/js/imagesloaded.min.js' ]: [ './node_modules/imagesloaded/imagesloaded.pkgd.min.js' ],
						[ WORKING_DIR + 'wp-includes/js/jquery/jquery.js' ]: [ './node_modules/jquery/dist/jquery.js' ],
						[ WORKING_DIR + 'wp-includes/js/jquery/jquery.min.js' ]: [ './node_modules/jquery/dist/jquery.min.js' ],
						[ WORKING_DIR + 'wp-includes/js/jquery/jquery.form.js' ]: [ './node_modules/jquery-form/src/jquery.form.js' ],
						[ WORKING_DIR + 'wp-includes/js/jquery/jquery.color.min.js' ]: [ './node_modules/jquery-color/dist/jquery.color.min.js' ],
						[ WORKING_DIR + 'wp-includes/js/masonry.min.js' ]: [ './node_modules/masonry-layout/dist/masonry.pkgd.min.js' ],
						[ WORKING_DIR + 'wp-includes/js/underscore.js' ]: [ './node_modules/underscore/underscore.js' ],
					}
				]
			},
			'codemirror': {
				options: {
					process: function( content, srcpath ) {
						if ( srcpath.includes( 'htmlhint.min.js' ) ) {
							return content + '\nif ( window.HTMLHint && window.HTMLHint.HTMLHint ) { window.HTMLHint = window.HTMLHint.HTMLHint; }';
						}
						return content;
					}
				},
				files: [
					{
						[ WORKING_DIR + 'wp-includes/js/codemirror/csslint.js' ]: [ './node_modules/csslint/dist/csslint.js' ],
						[ WORKING_DIR + 'wp-includes/js/codemirror/esprima.js' ]: [ './node_modules/esprima/dist/esprima.js' ],
						[ WORKING_DIR + 'wp-includes/js/codemirror/htmlhint.js' ]: [ './node_modules/htmlhint/dist/htmlhint.min.js' ],
						[ WORKING_DIR + 'wp-includes/js/codemirror/jsonlint.js' ]: [ './node_modules/jsonlint/web/jsonlint.js' ],
					},
					{
						expand: true,
						cwd: SOURCE_DIR + 'js/_enqueues/lib/codemirror/',
						src: [
							'htmlhint-kses.js',
						],
						dest: WORKING_DIR + 'wp-includes/js/codemirror/'
					},
					{
						expand: true,
						cwd: SOURCE_DIR + 'js/_enqueues/deprecated/',
						src: [
							'fakejshint.js',
						],
						dest: WORKING_DIR + 'wp-includes/js/codemirror/'
					}
				]
			},
			'vendor-js': {
				files: [
					{
						expand: true,
						cwd: SOURCE_DIR + 'js/_enqueues/vendor/',
						src: [
							'**/*',
							'!farbtastic.js',
							'!iris.min.js',
							'!deprecated/**',
							'!README.md',
							// Ignore unminified version of vendor lib we don't ship.
							'!jquery/jquery.masonry.js',
							'!tinymce/tinymce.js'
						],
						dest: WORKING_DIR + 'wp-includes/js/'
					},
					{
						expand: true,
						cwd: SOURCE_DIR + 'js/_enqueues/vendor/',
						src: [
							'farbtastic.js',
							'iris.min.js'
						],
						dest: WORKING_DIR + 'wp-admin/js/'
					},
					{
						expand: true,
						cwd: SOURCE_DIR + 'js/_enqueues/vendor/deprecated',
						src: [
							'suggest*'
						],
						dest: WORKING_DIR + 'wp-includes/js/jquery/'
					}
				].concat(
					// Copy tinymce.js only when building to /src.
					WORKING_DIR === SOURCE_DIR ? {
						expand: true,
						cwd: SOURCE_DIR + 'js/_enqueues/vendor/tinymce/',
						src: 'tinymce.js',
						dest: SOURCE_DIR + 'wp-includes/js/tinymce/'
					} : []
				)
			},
			'admin-js': {
				files: {
					[ WORKING_DIR + 'wp-admin/js/accordion.js' ]: [ './src/js/_enqueues/lib/accordion.js' ],
					[ WORKING_DIR + 'wp-admin/js/application-passwords.js' ]: [ './src/js/_enqueues/admin/application-passwords.js' ],
					[ WORKING_DIR + 'wp-admin/js/auth-app.js' ]: [ './src/js/_enqueues/admin/auth-app.js' ],
					[ WORKING_DIR + 'wp-admin/js/code-editor.js' ]: [ './src/js/_enqueues/wp/code-editor.js' ],
					[ WORKING_DIR + 'wp-admin/js/color-picker.js' ]: [ './src/js/_enqueues/lib/color-picker.js' ],
					[ WORKING_DIR + 'wp-admin/js/comment.js' ]: [ './src/js/_enqueues/admin/comment.js' ],
					[ WORKING_DIR + 'wp-admin/js/common.js' ]: [ './src/js/_enqueues/admin/common.js' ],
					[ WORKING_DIR + 'wp-admin/js/custom-background.js' ]: [ './src/js/_enqueues/admin/custom-background.js' ],
					[ WORKING_DIR + 'wp-admin/js/custom-header.js' ]: [ './src/js/_enqueues/admin/custom-header.js' ],
					[ WORKING_DIR + 'wp-admin/js/customize-controls.js' ]: [ './src/js/_enqueues/wp/customize/controls.js' ],
					[ WORKING_DIR + 'wp-admin/js/customize-nav-menus.js' ]: [ './src/js/_enqueues/wp/customize/nav-menus.js' ],
					[ WORKING_DIR + 'wp-admin/js/customize-widgets.js' ]: [ './src/js/_enqueues/wp/customize/widgets.js' ],
					[ WORKING_DIR + 'wp-admin/js/dashboard.js' ]: [ './src/js/_enqueues/wp/dashboard.js' ],
					[ WORKING_DIR + 'wp-admin/js/edit-comments.js' ]: [ './src/js/_enqueues/admin/edit-comments.js' ],
					[ WORKING_DIR + 'wp-admin/js/editor-expand.js' ]: [ './src/js/_enqueues/wp/editor/dfw.js' ],
					[ WORKING_DIR + 'wp-admin/js/editor.js' ]: [ './src/js/_enqueues/wp/editor/base.js' ],
					[ WORKING_DIR + 'wp-admin/js/gallery.js' ]: [ './src/js/_enqueues/lib/gallery.js' ],
					[ WORKING_DIR + 'wp-admin/js/image-edit.js' ]: [ './src/js/_enqueues/lib/image-edit.js' ],
					[ WORKING_DIR + 'wp-admin/js/inline-edit-post.js' ]: [ './src/js/_enqueues/admin/inline-edit-post.js' ],
					[ WORKING_DIR + 'wp-admin/js/inline-edit-tax.js' ]: [ './src/js/_enqueues/admin/inline-edit-tax.js' ],
					[ WORKING_DIR + 'wp-admin/js/language-chooser.js' ]: [ './src/js/_enqueues/lib/language-chooser.js' ],
					[ WORKING_DIR + 'wp-admin/js/link.js' ]: [ './src/js/_enqueues/admin/link.js' ],
					[ WORKING_DIR + 'wp-admin/js/media-gallery.js' ]: [ './src/js/_enqueues/deprecated/media-gallery.js' ],
					[ WORKING_DIR + 'wp-admin/js/media-upload.js' ]: [ './src/js/_enqueues/admin/media-upload.js' ],
					[ WORKING_DIR + 'wp-admin/js/media.js' ]: [ './src/js/_enqueues/admin/media.js' ],
					[ WORKING_DIR + 'wp-admin/js/nav-menu.js' ]: [ './src/js/_enqueues/lib/nav-menu.js' ],
					[ WORKING_DIR + 'wp-admin/js/password-strength-meter.js' ]: [ './src/js/_enqueues/wp/password-strength-meter.js' ],
					[ WORKING_DIR + 'wp-admin/js/password-toggle.js' ]: [ './src/js/_enqueues/admin/password-toggle.js' ],
					[ WORKING_DIR + 'wp-admin/js/plugin-install.js' ]: [ './src/js/_enqueues/admin/plugin-install.js' ],
					[ WORKING_DIR + 'wp-admin/js/post.js' ]: [ './src/js/_enqueues/admin/post.js' ],
					[ WORKING_DIR + 'wp-admin/js/postbox.js' ]: [ './src/js/_enqueues/admin/postbox.js' ],
					[ WORKING_DIR + 'wp-admin/js/revisions.js' ]: [ './src/js/_enqueues/wp/revisions.js' ],
					[ WORKING_DIR + 'wp-admin/js/set-post-thumbnail.js' ]: [ './src/js/_enqueues/admin/set-post-thumbnail.js' ],
					[ WORKING_DIR + 'wp-admin/js/svg-painter.js' ]: [ './src/js/_enqueues/wp/svg-painter.js' ],
					[ WORKING_DIR + 'wp-admin/js/tags-box.js' ]: [ './src/js/_enqueues/admin/tags-box.js' ],
					[ WORKING_DIR + 'wp-admin/js/tags-suggest.js' ]: [ './src/js/_enqueues/admin/tags-suggest.js' ],
					[ WORKING_DIR + 'wp-admin/js/tags.js' ]: [ './src/js/_enqueues/admin/tags.js' ],
					[ WORKING_DIR + 'wp-admin/js/site-health.js' ]: [ './src/js/_enqueues/admin/site-health.js' ],
					[ WORKING_DIR + 'wp-admin/js/site-icon.js' ]: [ './src/js/_enqueues/admin/site-icon.js' ],
					[ WORKING_DIR + 'wp-admin/js/privacy-tools.js' ]: [ './src/js/_enqueues/admin/privacy-tools.js' ],
					[ WORKING_DIR + 'wp-admin/js/theme-plugin-editor.js' ]: [ './src/js/_enqueues/wp/theme-plugin-editor.js' ],
					[ WORKING_DIR + 'wp-admin/js/theme.js' ]: [ './src/js/_enqueues/wp/theme.js' ],
					[ WORKING_DIR + 'wp-admin/js/updates.js' ]: [ './src/js/_enqueues/wp/updates.js' ],
					[ WORKING_DIR + 'wp-admin/js/user-profile.js' ]: [ './src/js/_enqueues/admin/user-profile.js' ],
					[ WORKING_DIR + 'wp-admin/js/user-suggest.js' ]: [ './src/js/_enqueues/lib/user-suggest.js' ],
					[ WORKING_DIR + 'wp-admin/js/widgets/custom-html-widgets.js' ]: [ './src/js/_enqueues/wp/widgets/custom-html.js' ],
					[ WORKING_DIR + 'wp-admin/js/widgets/media-audio-widget.js' ]: [ './src/js/_enqueues/wp/widgets/media-audio.js' ],
					[ WORKING_DIR + 'wp-admin/js/widgets/media-gallery-widget.js' ]: [ './src/js/_enqueues/wp/widgets/media-gallery.js' ],
					[ WORKING_DIR + 'wp-admin/js/widgets/media-image-widget.js' ]: [ './src/js/_enqueues/wp/widgets/media-image.js' ],
					[ WORKING_DIR + 'wp-admin/js/widgets/media-video-widget.js' ]: [ './src/js/_enqueues/wp/widgets/media-video.js' ],
					[ WORKING_DIR + 'wp-admin/js/widgets/media-widgets.js' ]: [ './src/js/_enqueues/wp/widgets/media.js' ],
					[ WORKING_DIR + 'wp-admin/js/widgets/text-widgets.js' ]: [ './src/js/_enqueues/wp/widgets/text.js' ],
					[ WORKING_DIR + 'wp-admin/js/widgets.js' ]: [ './src/js/_enqueues/admin/widgets.js' ],
					[ WORKING_DIR + 'wp-admin/js/word-count.js' ]: [ './src/js/_enqueues/wp/utils/word-count.js' ],
					[ WORKING_DIR + 'wp-admin/js/wp-fullscreen-stub.js' ]: [ './src/js/_enqueues/deprecated/fullscreen-stub.js' ],
					[ WORKING_DIR + 'wp-admin/js/xfn.js' ]: [ './src/js/_enqueues/admin/xfn.js' ]
				}
			},
			'includes-js': {
				files: {
					[ WORKING_DIR + 'wp-includes/js/admin-bar.js' ]: [ './src/js/_enqueues/lib/admin-bar.js' ],
					[ WORKING_DIR + 'wp-includes/js/api-request.js' ]: [ './src/js/_enqueues/wp/api-request.js' ],
					[ WORKING_DIR + 'wp-includes/js/autosave.js' ]: [ './src/js/_enqueues/wp/autosave.js' ],
					[ WORKING_DIR + 'wp-includes/js/comment-reply.js' ]: [ './src/js/_enqueues/lib/comment-reply.js' ],
					[ WORKING_DIR + 'wp-includes/js/customize-base.js' ]: [ './src/js/_enqueues/wp/customize/base.js' ],
					[ WORKING_DIR + 'wp-includes/js/customize-loader.js' ]: [ './src/js/_enqueues/wp/customize/loader.js' ],
					[ WORKING_DIR + 'wp-includes/js/customize-models.js' ]: [ './src/js/_enqueues/wp/customize/models.js' ],
					[ WORKING_DIR + 'wp-includes/js/customize-preview-nav-menus.js' ]: [ './src/js/_enqueues/wp/customize/preview-nav-menus.js' ],
					[ WORKING_DIR + 'wp-includes/js/customize-preview-widgets.js' ]: [ './src/js/_enqueues/wp/customize/preview-widgets.js' ],
					[ WORKING_DIR + 'wp-includes/js/customize-preview.js' ]: [ './src/js/_enqueues/wp/customize/preview.js' ],
					[ WORKING_DIR + 'wp-includes/js/customize-selective-refresh.js' ]: [ './src/js/_enqueues/wp/customize/selective-refresh.js' ],
					[ WORKING_DIR + 'wp-includes/js/customize-views.js' ]: [ './src/js/_enqueues/wp/customize/views.js' ],
					[ WORKING_DIR + 'wp-includes/js/heartbeat.js' ]: [ './src/js/_enqueues/wp/heartbeat.js' ],
					[ WORKING_DIR + 'wp-includes/js/mce-view.js' ]: [ './src/js/_enqueues/wp/mce-view.js' ],
					[ WORKING_DIR + 'wp-includes/js/media-editor.js' ]: [ './src/js/_enqueues/wp/media/editor.js' ],
					[ WORKING_DIR + 'wp-includes/js/quicktags.js' ]: [ './src/js/_enqueues/lib/quicktags.js' ],
					[ WORKING_DIR + 'wp-includes/js/shortcode.js' ]: [ './src/js/_enqueues/wp/shortcode.js' ],
					[ WORKING_DIR + 'wp-includes/js/utils.js' ]: [ './src/js/_enqueues/lib/cookies.js' ],
					[ WORKING_DIR + 'wp-includes/js/wp-ajax-response.js' ]: [ './src/js/_enqueues/lib/ajax-response.js' ],
					[ WORKING_DIR + 'wp-includes/js/wp-api.js' ]: [ './src/js/_enqueues/wp/api.js' ],
					[ WORKING_DIR + 'wp-includes/js/wp-auth-check.js' ]: [ './src/js/_enqueues/lib/auth-check.js' ],
					[ WORKING_DIR + 'wp-includes/js/wp-backbone.js' ]: [ './src/js/_enqueues/wp/backbone.js' ],
					[ WORKING_DIR + 'wp-includes/js/wp-custom-header.js' ]: [ './src/js/_enqueues/wp/custom-header.js' ],
					[ WORKING_DIR + 'wp-includes/js/wp-embed-template.js' ]: [ './src/js/_enqueues/lib/embed-template.js' ],
					[ WORKING_DIR + 'wp-includes/js/wp-embed.js' ]: [ './src/js/_enqueues/wp/embed.js' ],
					[ WORKING_DIR + 'wp-includes/js/wp-emoji-loader.js' ]: [ './src/js/_enqueues/lib/emoji-loader.js' ],
					[ WORKING_DIR + 'wp-includes/js/wp-emoji.js' ]: [ './src/js/_enqueues/wp/emoji.js' ],
					[ WORKING_DIR + 'wp-includes/js/wp-list-revisions.js' ]: [ './src/js/_enqueues/lib/list-revisions.js' ],
					[ WORKING_DIR + 'wp-includes/js/wp-lists.js' ]: [ './src/js/_enqueues/lib/lists.js' ],
					[ WORKING_DIR + 'wp-includes/js/wp-pointer.js' ]: [ './src/js/_enqueues/lib/pointer.js' ],
					[ WORKING_DIR + 'wp-includes/js/wp-sanitize.js' ]: [ './src/js/_enqueues/wp/sanitize.js' ],
					[ WORKING_DIR + 'wp-includes/js/wp-util.js' ]: [ './src/js/_enqueues/wp/util.js' ],
					[ WORKING_DIR + 'wp-includes/js/wpdialog.js' ]: [ './src/js/_enqueues/lib/dialog.js' ],
					[ WORKING_DIR + 'wp-includes/js/wplink.js' ]: [ './src/js/_enqueues/lib/link.js' ],
					[ WORKING_DIR + 'wp-includes/js/zxcvbn-async.js' ]: [ './src/js/_enqueues/lib/zxcvbn-async.js' ]
				}
			},
			'wp-admin-css-compat-rtl': {
				options: {
					processContent: function( src ) {
						return src.replace( /\.css/g, '-rtl.css' );
					}
				},
				src: SOURCE_DIR + 'wp-admin/css/wp-admin.css',
				dest: WORKING_DIR + 'wp-admin/css/wp-admin-rtl.css'
			},
			'wp-admin-css-compat-min': {
				options: {
					processContent: function( src ) {
						return src.replace( /\.css/g, '.min.css' );
					}
				},
				files: [
					{
						src: SOURCE_DIR + 'wp-admin/css/wp-admin.css',
						dest: WORKING_DIR + 'wp-admin/css/wp-admin.min.css'
					},
					{
						src:  WORKING_DIR + 'wp-admin/css/wp-admin-rtl.css',
						dest: WORKING_DIR + 'wp-admin/css/wp-admin-rtl.min.css'
					}
				]
			},
			version: {
				options: {
					processContent: function( src ) {
						return src.replace( /^\$wp_version = '(.+?)';/m, function( str, version ) {
							version = version.replace( /-src$/, '' );

							// If the version includes an SVN commit (-12345), it's not a released alpha/beta. Append a timestamp.
							version = version.replace( /-[\d]{5}$/, '-' + grunt.template.today( 'yyyymmdd.HHMMss' ) );

							/* jshint quotmark: true */
							return "$wp_version = '" + version + "';";
						});
					}
				},
				src: SOURCE_DIR + 'wp-includes/version.php',
				dest: BUILD_DIR + 'wp-includes/version.php'
			},
			dynamic: {
				dot: true,
				expand: true,
				cwd: SOURCE_DIR,
				dest: WORKING_DIR,
				src: []
			},
			'dynamic-js': {
				files: {}
			},
			qunit: {
				src: 'tests/qunit/index.html',
				dest: 'tests/qunit/compiled.html',
				options: {
					processContent: function( src ) {
						return src.replace( /(\".+?\/)build(\/.+?)(?:.min)?(.js\")/g , function( match, $1, $2, $3 ) {
							// Don't add `.min` to files that don't have it.
							return $1 + 'build' + $2 + ( /jquery$/.test( $2 ) ? '' : '.min' ) + $3;
						} );
					}
				}
			},
			'workflow-references-local-to-remote': {
				options: {
					processContent: function( src ) {
						return src.replace( /uses: \.\/\.github\/workflows\/([^\.]+)\.yml/g, function( match, $1 ) {
							return 'uses: WordPress/wordpress-develop/.github/workflows/' + $1 + '.yml@trunk';
						} );
					}
				},
				src: '.github/workflows/*.yml',
				dest: './'
			},
			'workflow-references-remote-to-local': {
				options: {
					processContent: function( src ) {
						return src.replace( /uses: WordPress\/wordpress-develop\/\.github\/workflows\/([^\.]+)\.yml@trunk/g, function( match, $1 ) {
							return 'uses: ./.github/workflows/' + $1 + '.yml';
						} );
					}
				},
				src: '.github/workflows/*.yml',
				dest: './'
			},
			certificates: {
				src: 'vendor/composer/ca-bundle/res/cacert.pem',
				dest: SOURCE_DIR + 'wp-includes/certificates/ca-bundle.crt'
			},
			// Gutenberg PHP infrastructure files (routes.php, pages.php, constants.php, pages/, routes/).
			'gutenberg-php': {
				options: {
					process: function( content ) {
						// Fix boot module asset file path for Core's different directory structure.
						return content.replace(
							/__DIR__\s*\.\s*(['"])\/..\/\..\/modules\/boot\/index\.min\.asset\.php\1/g,
							'ABSPATH . WPINC . \'/js/dist/script-modules/boot/index.min.asset.php\''
						);
					}
				},
				files: [ {
					expand: true,
					cwd: 'gutenberg/build',
					src: [
						'routes.php',
						'pages.php',
						'constants.php',
						'pages/**/*.php',
						'routes/**/*.php',
					],
					dest: WORKING_DIR + 'wp-includes/build/',
				} ],
			},
			'gutenberg-js': {
				files: [ {
					expand: true,
					cwd: 'gutenberg/build',
					src: [
						'pages/**/*.js',
						'routes/**/*.js',
					],
					dest: WORKING_DIR + 'wp-includes/build/',
				} ],
			},
			'gutenberg-modules': {
				files: [ {
					expand: true,
					cwd: 'gutenberg/build/modules',
					src: [ '**/*', '!**/*.map' ],
					dest: WORKING_DIR + 'wp-includes/js/dist/script-modules/',
				} ],
			},
			'gutenberg-styles': {
				files: [ {
					expand: true,
					cwd: 'gutenberg/build/styles',
					src: [ '**/*', '!**/*.map' ],
					dest: WORKING_DIR + 'wp-includes/css/dist/',
				} ],
			},
			'gutenberg-theme-json': {
				options: {
					process: function( content, srcpath ) {
						// Replace the local schema URL with the canonical public URL for Core.
						if ( path.basename( srcpath ) === 'theme.json' ) {
							return content.replace(
								'"$schema": "../schemas/json/theme.json"',
								'"$schema": "https://schemas.wp.org/trunk/theme.json"'
							);
						}
						return content;
					}
				},
				files: [
					{
						src: 'gutenberg/lib/theme.json',
						dest: WORKING_DIR + 'wp-includes/theme.json',
					},
					{
						src: 'gutenberg/lib/theme-i18n.json',
						dest: WORKING_DIR + 'wp-includes/theme-i18n.json',
					},
				],
			},
			'gutenberg-icons': {
				options: {
					process: function( content, srcpath ) {
						// Remove the 'gutenberg' text domain from _x() calls in manifest.php.
						if ( path.basename( srcpath ) === 'manifest.php' ) {
							return content.replace(
								/_x\(\s*([^,]+),\s*([^,]+),\s*['"]gutenberg['"]\s*\)/g,
								'_x( $1, $2 )'
							);
						}
						return content;
					}
				},
				files: [
					{
						src: 'gutenberg/packages/icons/src/manifest.php',
						dest: WORKING_DIR + 'wp-includes/icons/manifest.php',
					},
					{
						expand: true,
						cwd: 'gutenberg/packages/icons/src/library',
						src: '*.svg',
						dest: WORKING_DIR + 'wp-includes/icons/library/',
					},
				],
			},
		},
		sass: {
			colors: {
				expand: true,
				cwd: SOURCE_DIR,
				dest: WORKING_DIR,
				ext: '.css',
				src: ['wp-admin/css/colors/*/colors.scss'],
				options: {
					implementation: sass
				}
			}
		},
		cssmin: {
			options: {
				compatibility: 'ie11'
			},
			codemirror: {
				files: {
					[ WORKING_DIR + 'wp-includes/js/codemirror/codemirror.min.css' ]: [
						'node_modules/codemirror/lib/codemirror.css',
						'node_modules/codemirror/addon/hint/show-hint.css',
						'node_modules/codemirror/addon/lint/lint.css',
						'node_modules/codemirror/addon/dialog/dialog.css',
						'node_modules/codemirror/addon/display/fullscreen.css',
						'node_modules/codemirror/addon/fold/foldgutter.css',
						'node_modules/codemirror/addon/merge/merge.css',
						'node_modules/codemirror/addon/scroll/simplescrollbars.css',
						'node_modules/codemirror/addon/search/matchesonscrollbar.css',
						'node_modules/codemirror/addon/tern/tern.css'
					]
				}
			},
			core: {
				expand: true,
				cwd: WORKING_DIR,
				dest: WORKING_DIR,
				ext: '.min.css',
				src: [
					'wp-admin/css/*.css',
					'!wp-admin/css/wp-admin*.css',
					'wp-includes/css/*.css',
					'wp-includes/js/mediaelement/wp-mediaelement.css'
				]
			},
			rtl: {
				expand: true,
				cwd: WORKING_DIR,
				dest: WORKING_DIR,
				ext: '.min.css',
				src: [
					'wp-admin/css/*-rtl.css',
					'!wp-admin/css/wp-admin*.css',
					'wp-includes/css/*-rtl.css'
				]
			},
			colors: {
				expand: true,
				cwd: WORKING_DIR,
				dest: WORKING_DIR,
				ext: '.min.css',
				src: [
					'wp-admin/css/colors/*/*.css'
				]
			},
			themes: {
				expand: true,
				cwd: WORKING_DIR,
				dest: WORKING_DIR,
				ext: '.min.css',
				src: [
					'wp-content/themes/twentytwentytwo/style.css',
					'wp-content/themes/twentytwentyfive/style.css',
				]
			}
		},
		rtlcss: {
			options: {
				// rtlcss options.
				opts: {
					clean: false,
					processUrls: { atrule: true, decl: false },
					stringMap: [
						{
							name: 'import-rtl-stylesheet',
							priority: 10,
							exclusive: true,
							search: [ '.css' ],
							replace: [ '-rtl.css' ],
							options: {
								scope: 'url',
								ignoreCase: false
							}
						}
					]
				},
				saveUnmodified: false,
				plugins: [
					{
						name: 'swap-dashicons-left-right-arrows',
						priority: 10,
						directives: {
							control: {},
							value: []
						},
						processors: [
							{
								expr: /content/im,
								action: function( prop, value ) {
									if ( value === '"\\f141"' ) { // dashicons-arrow-left
										value = '"\\f139"';
									} else if ( value === '"\\f340"' ) { // dashicons-arrow-left-alt
										value = '"\\f344"';
									} else if ( value === '"\\f341"' ) { // dashicons-arrow-left-alt2
										value = '"\\f345"';
									} else if ( value === '"\\f139"' ) { // dashicons-arrow-right
										value = '"\\f141"';
									} else if ( value === '"\\f344"' ) { // dashicons-arrow-right-alt
										value = '"\\f340"';
									} else if ( value === '"\\f345"' ) { // dashicons-arrow-right-alt2
										value = '"\\f341"';
									}
									return { prop: prop, value: value };
								}
							}
						]
					}
				]
			},
			core: {
				expand: true,
				cwd: SOURCE_DIR,
				dest: WORKING_DIR,
				ext: '-rtl.css',
				src: [
					'wp-admin/css/*.css',
					'wp-includes/css/*.css',

					/*
					 * Exclude minified and already processed files, and files from external packages.
					 * These are present when running `grunt build` after `grunt --dev`.
					 */
					'!wp-admin/css/*-rtl.css',
					'!wp-includes/css/*-rtl.css',
					'!wp-admin/css/*.min.css',
					'!wp-includes/css/*.min.css',
					'!wp-includes/css/dist',

					// Exceptions.
					'!wp-includes/css/dashicons.css',
					'!wp-includes/css/wp-embed-template.css',
					'!wp-includes/css/wp-embed-template-ie.css'
				]
			},
			colors: {
				expand: true,
				cwd: WORKING_DIR,
				dest: WORKING_DIR,
				ext: '-rtl.css',
				src: [
					'wp-admin/css/colors/*/colors.css'
				]
			},
			dynamic: {
				expand: true,
				cwd: SOURCE_DIR,
				dest: WORKING_DIR,
				ext: '-rtl.css',
				src: []
			}
		},
		jshint: {
			options: grunt.file.readJSON( '.jshintrc' ),
			grunt: {
				src: ['Gruntfile.js']
			},
			tests: {
				src: [
					'tests/qunit/**/*.js',
					'!tests/qunit/vendor/*',
					'!tests/qunit/editor/**'
				],
				options: grunt.file.readJSON( 'tests/qunit/.jshintrc' )
			},
			themes: {
				expand: true,
				cwd: SOURCE_DIR + 'wp-content/themes',
				src: [
					'twenty*/**/*.js',
					'!twenty{eleven,twelve,thirteen}/**',
					// Third party scripts.
					'!twenty*/node_modules/**',
					'!twenty{fourteen,fifteen,sixteen}/js/html5.js',
					'!twentyseventeen/assets/js/html5.js',
					'!twentyseventeen/assets/js/jquery.scrollTo.js'
				]
			},
			media: {
				src: [
					SOURCE_DIR + 'js/media/**/*.js'
				]
			},
			core: {
				expand: true,
				cwd: SOURCE_DIR,
				src: [
					'js/_enqueues/**/*.js',
					// Third party scripts.
					'!js/_enqueues/vendor/**/*.js'
				],
				// Remove once other JSHint errors are resolved.
				options: {
					curly: false,
					eqeqeq: false
				},
				/*
				 * Limit JSHint's run to a single specified file:
				 *
				 *    grunt jshint:core --file=filename.js
				 *
				 * Optionally, include the file path:
				 *
				 *    grunt jshint:core --file=path/to/filename.js
				 */
				filter: function( filepath ) {
					var index, file = grunt.option( 'file' );

					// Don't filter when no target file is specified.
					if ( ! file ) {
						return true;
					}

					// Normalize filepath for Windows.
					filepath = filepath.replace( /\\/g, '/' );
					index = filepath.lastIndexOf( '/' + file );

					// Match only the filename passed from cli.
					if ( filepath === file || ( -1 !== index && index === filepath.length - ( file.length + 1 ) ) ) {
						return true;
					}

					return false;
				}
			},
			plugins: {
				expand: true,
				cwd: SOURCE_DIR + 'wp-content/plugins',
				src: [
					'**/*.js',
					'!**/*.min.js'
				],
				/*
				 * Limit JSHint's run to a single specified plugin directory:
				 *
				 *    grunt jshint:plugins --dir=foldername
				 */
				filter: function( dirpath ) {
					var index, dir = grunt.option( 'dir' );

					// Don't filter when no target folder is specified.
					if ( ! dir ) {
						return true;
					}

					dirpath = dirpath.replace( /\\/g, '/' );
					index = dirpath.lastIndexOf( '/' + dir );

					// Match only the folder name passed from cli.
					if ( -1 !== index ) {
						return true;
					}

					return false;
				}
			}
		},
		jsdoc : {
			dist : {
				dest: 'jsdoc',
				options: {
					configure : 'jsdoc.conf.json'
				}
			}
		},
		qunit: {
			files: [
				'tests/qunit/**/*.html',
				'!tests/qunit/editor/**'
			]
		},
		phpunit: {
			'default': {
				args: ['--verbose', '-c', 'phpunit.xml.dist']
			},
			ajax: {
				args: ['--verbose', '-c', 'phpunit.xml.dist', '--group', 'ajax']
			},
			multisite: {
				args: ['--verbose', '-c', 'tests/phpunit/multisite.xml']
			},
			'ms-ajax': {
				args: ['--verbose', '-c', 'tests/phpunit/multisite.xml', '--group', 'ajax']
			},
			'ms-files': {
				args: ['--verbose', '-c', 'tests/phpunit/multisite.xml', '--group', 'ms-files']
			},
			'external-http': {
				args: ['--verbose', '-c', 'phpunit.xml.dist', '--group', 'external-http']
			},
			'restapi-jsclient': {
				args: ['--verbose', '-c', 'phpunit.xml.dist', '--group', 'restapi-jsclient']
			}
		},
		uglify: {
			options: {
				parse: {
					module: false
				},
				compress: {
					module: false
				},
				output: {
					module: false,
					ascii_only: true
				}
			},
			core: {
				expand: true,
				cwd: WORKING_DIR,
				dest: WORKING_DIR,
				ext: '.min.js',
				src: [
					'wp-admin/js/**/*.js',
					'wp-includes/js/*.js',
					'wp-includes/js/plupload/*.js',
					'wp-includes/js/mediaelement/wp-mediaelement.js',
					'wp-includes/js/mediaelement/wp-playlist.js',
					'wp-includes/js/mediaelement/mediaelement-migrate.js',
					'wp-includes/js/tinymce/plugins/wordpress/plugin.js',
					'wp-includes/js/tinymce/plugins/wp*/plugin.js',

					// Exceptions.
					'!{wp-admin,wp-includes}/**/*.min.js',
					'!wp-admin/js/custom-header.js', // Why? We should minify this.
					'!wp-admin/js/farbtastic.js',
					'!wp-includes/js/wp-emoji-loader.js', // This is a module. See the emoji-loader task below.
				]
			},
			'emoji-loader': {
				options: {
					module: true,
					toplevel: true,
				},
				src: WORKING_DIR + 'wp-includes/js/wp-emoji-loader.js',
				dest: WORKING_DIR + 'wp-includes/js/wp-emoji-loader.min.js',
			},
			'jquery-ui': {
				options: {
					// Preserve comments that start with a bang.
					output: {
						comments: /^!/
					}
				},
				expand: true,
				cwd: WORKING_DIR + 'wp-includes/js/jquery/ui/',
				dest: WORKING_DIR + 'wp-includes/js/jquery/ui/',
				ext: '.min.js',
				src: ['*.js']
			},
			imgareaselect: {
				src: WORKING_DIR + 'wp-includes/js/imgareaselect/jquery.imgareaselect.js',
				dest: WORKING_DIR + 'wp-includes/js/imgareaselect/jquery.imgareaselect.min.js'
			},
			jqueryform: {
				src: WORKING_DIR + 'wp-includes/js/jquery/jquery.form.js',
				dest: WORKING_DIR + 'wp-includes/js/jquery/jquery.form.min.js'
			},
			moment: {
				src: WORKING_DIR + 'wp-includes/js/dist/vendor/moment.js',
				dest: WORKING_DIR + 'wp-includes/js/dist/vendor/moment.min.js'
			},
			dynamic: {
				expand: true,
				cwd: WORKING_DIR,
				dest: WORKING_DIR,
				ext: '.min.js',
				src: []
			}
		},
		webpack: {
			prod: webpackConfig( { environment: 'production', buildTarget: WORKING_DIR } ),
			dev: webpackConfig( { environment: 'development', buildTarget: WORKING_DIR } ),
			watch: webpackConfig( { environment: 'development', watch: true } ),
			codemirror: require( './tools/webpack/codemirror.config.js' )( { buildTarget: WORKING_DIR } ),
		},
		concat: {
			tinymce: {
				options: {
					separator: '\n',
					process: function( src, filepath ) {
						return '// Source: ' + filepath.replace( WORKING_DIR, '' ) + '\n' + src;
					}
				},
				src: [
					WORKING_DIR + 'wp-includes/js/tinymce/tinymce.min.js',
					WORKING_DIR + 'wp-includes/js/tinymce/themes/modern/theme.min.js',
					WORKING_DIR + 'wp-includes/js/tinymce/plugins/*/plugin.min.js'
				],
				dest: WORKING_DIR + 'wp-includes/js/tinymce/wp-tinymce.js'
			},
			emoji: {
				options: {
					separator: '\n',
					process: function( src, filepath ) {
						return '// Source: ' + filepath.replace( WORKING_DIR, '' ) + '\n' + src;
					}
				},
				src: [
					WORKING_DIR + 'wp-includes/js/twemoji.min.js',
					WORKING_DIR + 'wp-includes/js/wp-emoji.min.js'
				],
				dest: WORKING_DIR + 'wp-includes/js/wp-emoji-release.min.js'
			}
		},
		patch:{
			options: {
				file_mappings: {
					'src/wp-admin/js/accordion.js': 'src/js/_enqueues/lib/accordion.js',
					'src/wp-admin/js/application-passwords.js': 'src/js/_enqueues/admin/application-passwords.js',
					'src/wp-admin/js/auth-app.js': 'src/js/_enqueues/admin/auth-app.js',
					'src/wp-admin/js/code-editor.js': 'src/js/_enqueues/wp/code-editor.js',
					'src/wp-admin/js/color-picker.js': 'src/js/_enqueues/lib/color-picker.js',
					'src/wp-admin/js/comment.js': 'src/js/_enqueues/admin/comment.js',
					'src/wp-admin/js/common.js': 'src/js/_enqueues/admin/common.js',
					'src/wp-admin/js/custom-background.js': 'src/js/_enqueues/admin/custom-background.js',
					'src/wp-admin/js/custom-header.js': 'src/js/_enqueues/admin/custom-header.js',
					'src/wp-admin/js/customize-controls.js': 'src/js/_enqueues/wp/customize/controls.js',
					'src/wp-admin/js/customize-nav-menus.js': 'src/js/_enqueues/wp/customize/nav-menus.js',
					'src/wp-admin/js/customize-widgets.js': 'src/js/_enqueues/wp/customize/widgets.js',
					'src/wp-admin/js/dashboard.js': 'src/js/_enqueues/wp/dashboard.js',
					'src/wp-admin/js/edit-comments.js': 'src/js/_enqueues/admin/edit-comments.js',
					'src/wp-admin/js/editor-expand.js': 'src/js/_enqueues/wp/editor/dfw.js',
					'src/wp-admin/js/editor.js': 'src/js/_enqueues/wp/editor/base.js',
					'src/wp-admin/js/gallery.js': 'src/js/_enqueues/lib/gallery.js',
					'src/wp-admin/js/image-edit.js': 'src/js/_enqueues/lib/image-edit.js',
					'src/wp-admin/js/inline-edit-post.js': 'src/js/_enqueues/admin/inline-edit-post.js',
					'src/wp-admin/js/inline-edit-tax.js': 'src/js/_enqueues/admin/inline-edit-tax.js',
					'src/wp-admin/js/language-chooser.js': 'src/js/_enqueues/lib/language-chooser.js',
					'src/wp-admin/js/link.js': 'src/js/_enqueues/admin/link.js',
					'src/wp-admin/js/media-gallery.js': 'src/js/_enqueues/deprecated/media-gallery.js',
					'src/wp-admin/js/media-upload.js': 'src/js/_enqueues/admin/media-upload.js',
					'src/wp-admin/js/media.js': 'src/js/_enqueues/admin/media.js',
					'src/wp-admin/js/nav-menu.js': 'src/js/_enqueues/lib/nav-menu.js',
					'src/wp-admin/js/password-strength-meter.js': 'src/js/_enqueues/wp/password-strength-meter.js',
					'src/wp-admin/js/plugin-install.js': 'src/js/_enqueues/admin/plugin-install.js',
					'src/wp-admin/js/post.js': 'src/js/_enqueues/admin/post.js',
					'src/wp-admin/js/postbox.js': 'src/js/_enqueues/admin/postbox.js',
					'src/wp-admin/js/revisions.js': 'src/js/_enqueues/wp/revisions.js',
					'src/wp-admin/js/set-post-thumbnail.js': 'src/js/_enqueues/admin/set-post-thumbnail.js',
					'src/wp-admin/js/svg-painter.js': 'src/js/_enqueues/wp/svg-painter.js',
					'src/wp-admin/js/tags-box.js': 'src/js/_enqueues/admin/tags-box.js',
					'src/wp-admin/js/tags-suggest.js': 'src/js/_enqueues/admin/tags-suggest.js',
					'src/wp-admin/js/tags.js': 'src/js/_enqueues/admin/tags.js',
					'src/wp-admin/js/theme-plugin-editor.js': 'src/js/_enqueues/wp/theme-plugin-editor.js',
					'src/wp-admin/js/theme.js': 'src/js/_enqueues/wp/theme.js',
					'src/wp-admin/js/updates.js': 'src/js/_enqueues/wp/updates.js',
					'src/wp-admin/js/user-profile.js': 'src/js/_enqueues/admin/user-profile.js',
					'src/wp-admin/js/user-suggest.js': 'src/js/_enqueues/lib/user-suggest.js',
					'src/wp-admin/js/widgets/custom-html-widgets.js': 'src/js/_enqueues/wp/widgets/custom-html.js',
					'src/wp-admin/js/widgets/media-audio-widget.js': 'src/js/_enqueues/wp/widgets/media-audio.js',
					'src/wp-admin/js/widgets/media-gallery-widget.js': 'src/js/_enqueues/wp/widgets/media-gallery.js',
					'src/wp-admin/js/widgets/media-image-widget.js': 'src/js/_enqueues/wp/widgets/media-image.js',
					'src/wp-admin/js/widgets/media-video-widget.js': 'src/js/_enqueues/wp/widgets/media-video.js',
					'src/wp-admin/js/widgets/media-widgets.js': 'src/js/_enqueues/wp/widgets/media.js',
					'src/wp-admin/js/widgets/text-widgets.js': 'src/js/_enqueues/wp/widgets/text.js',
					'src/wp-admin/js/widgets.js': 'src/js/_enqueues/admin/widgets.js',
					'src/wp-admin/js/word-count.js': 'src/js/_enqueues/wp/utils/word-count.js',
					'src/wp-admin/js/wp-fullscreen-stub.js': 'src/js/_enqueues/deprecated/fullscreen-stub.js',
					'src/wp-admin/js/xfn.js': 'src/js/_enqueues/admin/xfn.js',
					'src/wp-includes/js/admin-bar.js': 'src/js/_enqueues/lib/admin-bar.js',
					'src/wp-includes/js/api-request.js': 'src/js/_enqueues/wp/api-request.js',
					'src/wp-includes/js/autosave.js': 'src/js/_enqueues/wp/autosave.js',
					'src/wp-includes/js/comment-reply.js': 'src/js/_enqueues/lib/comment-reply.js',
					'src/wp-includes/js/customize-base.js': 'src/js/_enqueues/wp/customize/base.js',
					'src/wp-includes/js/customize-loader.js': 'src/js/_enqueues/wp/customize/loader.js',
					'src/wp-includes/js/customize-models.js': 'src/js/_enqueues/wp/customize/models.js',
					'src/wp-includes/js/customize-preview-nav-menus.js': 'src/js/_enqueues/wp/customize/preview-nav-menus.js',
					'src/wp-includes/js/customize-preview-widgets.js': 'src/js/_enqueues/wp/customize/preview-widgets.js',
					'src/wp-includes/js/customize-preview.js': 'src/js/_enqueues/wp/customize/preview.js',
					'src/wp-includes/js/customize-selective-refresh.js': 'src/js/_enqueues/wp/customize/selective-refresh.js',
					'src/wp-includes/js/customize-views.js': 'src/js/_enqueues/wp/customize/views.js',
					'src/wp-includes/js/heartbeat.js': 'src/js/_enqueues/wp/heartbeat.js',
					'src/wp-includes/js/mce-view.js': 'src/js/_enqueues/wp/mce-view.js',
					'src/wp-includes/js/media-editor.js': 'src/js/_enqueues/wp/media/editor.js',
					'src/wp-includes/js/quicktags.js': 'src/js/_enqueues/lib/quicktags.js',
					'src/wp-includes/js/shortcode.js': 'src/js/_enqueues/wp/shortcode.js',
					'src/wp-includes/js/utils.js': 'src/js/_enqueues/lib/cookies.js',
					'src/wp-includes/js/wp-ajax-response.js': 'src/js/_enqueues/lib/ajax-response.js',
					'src/wp-includes/js/wp-api.js': 'src/js/_enqueues/wp/api.js',
					'src/wp-includes/js/wp-auth-check.js': 'src/js/_enqueues/lib/auth-check.js',
					'src/wp-includes/js/wp-backbone.js': 'src/js/_enqueues/wp/backbone.js',
					'src/wp-includes/js/wp-custom-header.js': 'src/js/_enqueues/wp/custom-header.js',
					'src/wp-includes/js/wp-embed-template.js': 'src/js/_enqueues/lib/embed-template.js',
					'src/wp-includes/js/wp-embed.js': 'src/js/_enqueues/wp/embed.js',
					'src/wp-includes/js/wp-emoji-loader.js': 'src/js/_enqueues/lib/emoji-loader.js',
					'src/wp-includes/js/wp-emoji.js': 'src/js/_enqueues/wp/emoji.js',
					'src/wp-includes/js/wp-list-revisions.js': 'src/js/_enqueues/lib/list-revisions.js',
					'src/wp-includes/js/wp-lists.js': 'src/js/_enqueues/lib/lists.js',
					'src/wp-includes/js/wp-pointer.js': 'src/js/_enqueues/lib/pointer.js',
					'src/wp-includes/js/wp-sanitize.js': 'src/js/_enqueues/wp/sanitize.js',
					'src/wp-includes/js/wp-util.js': 'src/js/_enqueues/wp/util.js',
					'src/wp-includes/js/wpdialog.js': 'src/js/_enqueues/lib/dialog.js',
					'src/wp-includes/js/wplink.js': 'src/js/_enqueues/lib/link.js',
					'src/wp-includes/js/zxcvbn-async.js': 'src/js/_enqueues/lib/zxcvbn-async.js',
					'src/wp-includes/js/media/controllers/audio-details.js' : 'src/js/media/controllers/audio-details.js',
					'src/wp-includes/js/media/controllers/collection-add.js' : 'src/js/media/controllers/collection-add.js',
					'src/wp-includes/js/media/controllers/collection-edit.js' : 'src/js/media/controllers/collection-edit.js',
					'src/wp-includes/js/media/controllers/cropper.js' : 'src/js/media/controllers/cropper.js',
					'src/wp-includes/js/media/controllers/customize-image-cropper.js' : 'src/js/media/controllers/customize-image-cropper.js',
					'src/wp-includes/js/media/controllers/edit-attachment-metadata.js' : 'src/js/media/controllers/edit-attachment-metadata.js',
					'src/wp-includes/js/media/controllers/edit-image.js' : 'src/js/media/controllers/edit-image.js',
					'src/wp-includes/js/media/controllers/embed.js' : 'src/js/media/controllers/embed.js',
					'src/wp-includes/js/media/controllers/featured-image.js' : 'src/js/media/controllers/featured-image.js',
					'src/wp-includes/js/media/controllers/gallery-add.js' : 'src/js/media/controllers/gallery-add.js',
					'src/wp-includes/js/media/controllers/gallery-edit.js' : 'src/js/media/controllers/gallery-edit.js',
					'src/wp-includes/js/media/controllers/image-details.js' : 'src/js/media/controllers/image-details.js',
					'src/wp-includes/js/media/controllers/library.js' : 'src/js/media/controllers/library.js',
					'src/wp-includes/js/media/controllers/media-library.js' : 'src/js/media/controllers/media-library.js',
					'src/wp-includes/js/media/controllers/region.js' : 'src/js/media/controllers/region.js',
					'src/wp-includes/js/media/controllers/replace-image.js' : 'src/js/media/controllers/replace-image.js',
					'src/wp-includes/js/media/controllers/site-icon-cropper.js' : 'src/js/media/controllers/site-icon-cropper.js',
					'src/wp-includes/js/media/controllers/state-machine.js' : 'src/js/media/controllers/state-machine.js',
					'src/wp-includes/js/media/controllers/state.js' : 'src/js/media/controllers/state.js',
					'src/wp-includes/js/media/controllers/video-details.js' : 'src/js/media/controllers/video-details.js',
					'src/wp-includes/js/media/models/attachment.js' : 'src/js/media/models/attachment.js',
					'src/wp-includes/js/media/models/attachments.js' : 'src/js/media/models/attachments.js',
					'src/wp-includes/js/media/models/post-image.js' : 'src/js/media/models/post-image.js',
					'src/wp-includes/js/media/models/post-media.js' : 'src/js/media/models/post-media.js',
					'src/wp-includes/js/media/models/query.js' : 'src/js/media/models/query.js',
					'src/wp-includes/js/media/models/selection.js' : 'src/js/media/models/selection.js',
					'src/wp-includes/js/media/routers/manage.js' : 'src/js/media/routers/manage.js',
					'src/wp-includes/js/media/utils/selection-sync.js' : 'src/js/media/utils/selection-sync.js',
					'src/wp-includes/js/media/views/attachment-compat.js' : 'src/js/media/views/attachment-compat.js',
					'src/wp-includes/js/media/views/attachment-filters.js' : 'src/js/media/views/attachment-filters.js',
					'src/wp-includes/js/media/views/attachment-filters/all.js' : 'src/js/media/views/attachment-filters/all.js',
					'src/wp-includes/js/media/views/attachment-filters/date.js' : 'src/js/media/views/attachment-filters/date.js',
					'src/wp-includes/js/media/views/attachment-filters/uploaded.js' : 'src/js/media/views/attachment-filters/uploaded.js',
					'src/wp-includes/js/media/views/attachment.js' : 'src/js/media/views/attachment.js',
					'src/wp-includes/js/media/views/attachment/details-two-column.js' : 'src/js/media/views/details-two-column.js',
					'src/wp-includes/js/media/views/attachment/details.js' : 'src/js/media/views/details.js',
					'src/wp-includes/js/media/views/attachment/edit-library.js' : 'src/js/media/views/edit-library.js',
					'src/wp-includes/js/media/views/attachment/edit-selection.js' : 'src/js/media/views/edit-selection.js',
					'src/wp-includes/js/media/views/attachment/library.js' : 'src/js/media/views/library.js',
					'src/wp-includes/js/media/views/attachment/selection.js' : 'src/js/media/views/selection.js',
					'src/wp-includes/js/media/views/attachment/attachments.js' : 'src/js/media/views/attachments.js',
					'src/wp-includes/js/media/views/attachments/browser.js' : 'src/js/media/views/attachments/browser.js',
					'src/wp-includes/js/media/views/attachments/selection.js' : 'src/js/media/views/attachments/selection.js',
					'src/wp-includes/js/media/views/attachments/audio-details.js' : 'src/js/media/views/attachments/audio-details.js',
					'src/wp-includes/js/media/views/attachments/button-group.js' : 'src/js/media/views/attachments/button-group.js',
					'src/wp-includes/js/media/views/attachments/button.js' : 'src/js/media/views/attachments/button.js',
					'src/wp-includes/js/media/views/button/delete-selected-permanently.js' : 'src/js/media/views/button/delete-selected-permanently.js',
					'src/wp-includes/js/media/views/button/delete-selected.js' : 'src/js/media/views/button/delete-selected.js',
					'src/wp-includes/js/media/views/button/select-mode-toggle.js' : 'src/js/media/views/button/select-mode-toggle.js',
					'src/wp-includes/js/media/views/cropper.js' : 'src/js/media/views/cropper.js',
					'src/wp-includes/js/media/views/edit-image-details.js' : 'src/js/media/views/edit-image-details.js',
					'src/wp-includes/js/media/views/edit-image.js' : 'src/js/media/views/edit-image.js',
					'src/wp-includes/js/media/views/embed.js' : 'src/js/media/views/embed.js',
					'src/wp-includes/js/media/views/embed/image.js' : 'src/js/media/views/embed/image.js',
					'src/wp-includes/js/media/views/embed/link.js' : 'src/js/media/views/embed/link.js',
					'src/wp-includes/js/media/views/embed/url.js' : 'src/js/media/views/embed/url.js',
					'src/wp-includes/js/media/views/focus-manager.js' : 'src/js/media/views/focus-manager.js',
					'src/wp-includes/js/media/views/frame.js' : 'src/js/media/views/frame.js',
					'src/wp-includes/js/media/views/frame/audio-details.js' : 'src/js/media/views/frame/audio-details.js',
					'src/wp-includes/js/media/views/frame/edit-attachments.js' : 'src/js/media/views/frame/edit-attachments.js',
					'src/wp-includes/js/media/views/frame/image-details.js' : 'src/js/media/views/frame/image-details.js',
					'src/wp-includes/js/media/views/frame/manage.js' : 'src/js/media/views/frame/manage.js',
					'src/wp-includes/js/media/views/frame/media-details.js' : 'src/js/media/views/frame/media-details.js',
					'src/wp-includes/js/media/views/frame/post.js' : 'src/js/media/views/frame/post.js',
					'src/wp-includes/js/media/views/frame/select.js' : 'src/js/media/views/frame/select.js',
					'src/wp-includes/js/media/views/frame/video-details.js' : 'src/js/media/views/frame/video-details.js',
					'src/wp-includes/js/media/views/iframe.js' : 'src/js/media/views/iframe.js',
					'src/wp-includes/js/media/views/image-details.js' : 'src/js/media/views/image-details.js',
					'src/wp-includes/js/media/views/label.js' : 'src/js/media/views/label.js',
					'src/wp-includes/js/media/views/media-details.js' : 'src/js/media/views/media-details.js',
					'src/wp-includes/js/media/views/media-frame.js' : 'src/js/media/views/media-frame.js',
					'src/wp-includes/js/media/views/menu-item.js' : 'src/js/media/views/menu-item.js',
					'src/wp-includes/js/media/views/menu.js' : 'src/js/media/views/menu.js',
					'src/wp-includes/js/media/views/modal.js' : 'src/js/media/views/modal.js',
					'src/wp-includes/js/media/views/priority-list.js' : 'src/js/media/views/priority-list.js',
					'src/wp-includes/js/media/views/router-item.js' : 'src/js/media/views/router-item.js',
					'src/wp-includes/js/media/views/router.js' : 'src/js/media/views/router.js',
					'src/wp-includes/js/media/views/search.js' : 'src/js/media/views/search.js',
					'src/wp-includes/js/media/views/selection.js' : 'src/js/media/views/selection.js',
					'src/wp-includes/js/media/views/settings.js' : 'src/js/media/views/settings.js',
					'src/wp-includes/js/media/views/settings/attachment-display.js' : 'src/js/media/views/settings/attachment-display.js',
					'src/wp-includes/js/media/views/settings/gallery.js' : 'src/js/media/views/settings/gallery.js',
					'src/wp-includes/js/media/views/settings/playlist.js' : 'src/js/media/views/settings/playlist.js',
					'src/wp-includes/js/media/views/sidebar.js' : 'src/js/media/views/sidebar.js',
					'src/wp-includes/js/media/views/site-icon-cropper.js' : 'src/js/media/views/site-icon-cropper.js',
					'src/wp-includes/js/media/views/site-icon-preview.js' : 'src/js/media/views/site-icon-preview.js',
					'src/wp-includes/js/media/views/spinner.js' : 'src/js/media/views/spinner.js',
					'src/wp-includes/js/media/views/toolbar.js' : 'src/js/media/views/toolbar.js',
					'src/wp-includes/js/media/views/toolbar/embed.js' : 'src/js/media/views/toolbar/embed.js',
					'src/wp-includes/js/media/views/toolbar/select.js' : 'src/js/media/views/toolbar/select.js',
					'src/wp-includes/js/media/views/uploader/editor.js' : 'src/js/media/views/uploader/editor.js',
					'src/wp-includes/js/media/views/uploader/inline.js' : 'src/js/media/views/uploader/inline.js',
					'src/wp-includes/js/media/views/uploader/status-error.js' : 'src/js/media/views/uploader/status-error.js',
					'src/wp-includes/js/media/views/uploader/status.js' : 'src/js/media/views/uploader/status.js',
					'src/wp-includes/js/media/views/uploader/window.js' : 'src/js/media/views/uploader/window.js',
					'src/wp-includes/js/media/views/video-details.js' : 'src/js/media/views/video-details.js',
					'src/wp-includes/js/media/views/view.js' : 'src/js/media/views/view.js'
				}
			}
		},
		imagemin: {
			core: {
				expand: true,
				cwd: SOURCE_DIR,
				src: [
					'wp-{admin,includes}/images/**/*.{png,jpg,gif,jpeg}',
					'wp-content/themes/**/*.{png,jpg,gif,jpeg}',
					'wp-includes/js/tinymce/skins/wordpress/images/*.{png,jpg,gif,jpeg}'
				],
				dest: SOURCE_DIR
			}
		},
		replace: {
			'source-maps': {
				options: {
					patterns: [
						{
							match: new RegExp( '\/\/# sourceMappingURL=.*\\s*', 'g' ),
							replacement: ''
						}
					]
				},
				files: [
					{
						expand: true,
						flatten: true,
						src: [
							BUILD_DIR + 'wp-includes/js/underscore.js'
						],
						dest: BUILD_DIR + 'wp-includes/js/'
					},
					{
						expand: true,
						cwd: BUILD_DIR + 'wp-includes/js/dist/',
						src: [ '*.js' ],
						dest: BUILD_DIR + 'wp-includes/js/dist/',
					},
					{
						expand: true,
						cwd: BUILD_DIR + 'wp-includes/js/dist/vendor/',
						src: [ '**/*.js' ],
						dest: BUILD_DIR + 'wp-includes/js/dist/vendor/',
					},
					{
						expand: true,
						cwd: BUILD_DIR + 'wp-includes/js/dist/script-modules/',
						src: [ '**/*.js' ],
						dest: BUILD_DIR + 'wp-includes/js/dist/script-modules/',
					}
				]
			}
		},
		_watch: {
			options: {
				interval: 2000
			},
			all: {
				files: [
					SOURCE_DIR + '**',
					'!' + SOURCE_DIR + 'js/**/*.js',
					// Ignore version control directories.
					'!' + SOURCE_DIR + '**/.{svn,git}/**',
					// Ignore third-party plugins.
					'!' + SOURCE_DIR + 'wp-content/plugins/**',
					/*
					 * Ignore the generated class map, and the temporary file it is
					 * published through. `build:autoload-classmap:dynamic` below rewrites
					 * the map whenever a watched PHP file changes, and watching its own
					 * output would make that rewrite the next change to react to.
					 */
					'!' + AUTOLOAD_CLASSMAP_FILE,
					'!' + AUTOLOAD_CLASSMAP_FILE + '.tmp*'
				],
				tasks: [ 'build:autoload-classmap:dynamic', 'clean:dynamic', 'copy:dynamic' ],
				options: {
					dot: true,
					spawn: false
				}
			},
			'js-enqueues': {
				files: [SOURCE_DIR + 'js/_enqueues/**/*.js'],
				tasks: ['clean:dynamic', 'copy:dynamic-js', 'uglify:dynamic'],
				options: {
					dot: true,
					spawn: false
				}
			},
			'js-webpack': {
				files: [
					SOURCE_DIR + 'js/**/*.js',
					'!' + SOURCE_DIR + 'js/_enqueues/**/*.js',
					'webpack-dev.config.js'
				],
				tasks: ['clean:dynamic', 'webpack:dev', 'uglify:dynamic'],
				options: {
					dot: true,
					spawn: false
				}
			},
			config: {
				files: [
					'Gruntfile.js',
					'webpack.config.js'
				]
			},
			colors: {
				files: [SOURCE_DIR + 'wp-admin/css/colors/**'],
				tasks: ['sass:colors']
			},
			rtl: {
				files: [
					SOURCE_DIR + 'wp-admin/css/*.css',
					SOURCE_DIR + 'wp-includes/css/*.css'
				],
				tasks: ['rtlcss:dynamic'],
				options: {
					spawn: false
				}
			},
			test: {
				files: [
					'tests/qunit/**',
					'!tests/qunit/editor/**'
				],
				tasks: ['qunit']
			}
		}
	});

	// Allow builds to be minimal.
	if( grunt.option( 'minimal-copy' ) ) {
		var copyFilesOptions = grunt.config.get( 'copy.files.files' );
		copyFilesOptions[0].src.push( '!wp-content/plugins/**' );
		copyFilesOptions[0].src.push( '!wp-content/themes/!(twenty*)/**' );
		grunt.config.set( 'copy.files.files', copyFilesOptions );
	}


	// Register tasks.

	// Webpack task.
	grunt.loadNpmTasks( 'grunt-webpack' );

	// RTL task.
	grunt.registerTask('rtl', ['rtlcss:core', 'rtlcss:colors']);

	// Color schemes task.
	grunt.registerTask('colors', ['sass:colors', 'postcss:colors']);

	// JSHint task.
	grunt.registerTask( 'jshint:corejs', [
		'jshint:grunt',
		'jshint:tests',
		'jshint:themes',
		'jshint:core',
		'jshint:media'
	] );

	grunt.registerTask( 'restapi-jsclient', [
		'phpunit:restapi-jsclient',
		'qunit:compiled'
	] );

	grunt.registerTask( 'sync-gutenberg-packages', function() {
		if ( grunt.option( 'update-browserlist' ) ) {
			/*
			 * Updating the browserlist database is opt-in and up to the release lead.
			 *
			 * Browserlist database should be updated:
			 * - In each release cycle up until RC1
			 * - If Webpack throws a warning about an outdated database
			 *
			 * It should not be updated:
			 * - After the RC1
			 * - When backporting fixes to older WordPress releases.
			 *
			 * For more context, see:
			 * https://github.com/WordPress/wordpress-develop/pull/2621#discussion_r859840515
			 * https://core.trac.wordpress.org/ticket/55559
			 */
			grunt.task.run( 'browserslist:update' );
		}

		// Install the latest version of the packages already listed in package.json.
		grunt.task.run( 'wp-packages:update' );

		/*
		 * Install any new @wordpress packages that are now required.
		 * Update any non-@wordpress deps to the same version as required in the @wordpress packages (e.g. react 16 -> 17).
		 */
		grunt.task.run( 'wp-packages:refresh-deps' );
	} );

	// Gutenberg integration tasks.
	grunt.registerTask( 'gutenberg:verify', 'Verifies the installed Gutenberg version matches the expected SHA.', function() {
		const done = this.async();
		grunt.util.spawn( {
			cmd: 'node',
			args: [ 'tools/gutenberg/utils.js' ],
			opts: { stdio: 'inherit' }
		}, function( error ) {
			done( ! error );
		} );
	} );

	grunt.registerTask( 'gutenberg:download', 'Downloads the built Gutenberg artifact.', function() {
		const done = this.async();
		const args = [ 'tools/gutenberg/download.js' ];
		if ( grunt.option( 'force' ) ) {
			args.push( '--force' );
		}
		grunt.util.spawn( {
			cmd: 'node',
			args,
			opts: { stdio: 'inherit' }
		}, function( error ) {
			done( ! error );
		} );
	} );

	grunt.registerTask( 'gutenberg:copy', 'Copies Gutenberg JS packages and block assets to WordPress Core.', function() {
		const done = this.async();
		const buildDir = grunt.option( 'dev' ) ? 'src' : 'build';
		grunt.util.spawn( {
			cmd: 'node',
			args: [ 'tools/gutenberg/copy.js', `--build-dir=${ buildDir }` ],
			opts: { stdio: 'inherit' }
		}, function( error ) {
			done( ! error );
		} );
	} );

	grunt.registerTask( 'copy-vendor-scripts', 'Copies vendor scripts from node_modules to wp-includes/js/dist/vendor/.', function() {
		const done = this.async();
		const buildDir = grunt.option( 'dev' ) ? 'src' : 'build';
		grunt.util.spawn( {
			cmd: 'node',
			args: [ 'tools/vendors/copy-vendors.js', `--build-dir=${ buildDir }` ],
			opts: { stdio: 'inherit' }
		}, function( error ) {
			done( ! error );
		} );
	} );

	grunt.renameTask( 'watch', '_watch' );

	grunt.registerTask( 'watch', function() {
		if ( ! this.args.length || this.args.indexOf( 'webpack' ) > -1 ) {
			grunt.task.run( 'build' );
		}

		if ( 'watch:phpunit' === grunt.cli.tasks[ 0 ] || 'undefined' !== typeof grunt.option( 'phpunit' ) ) {
			grunt.config.data._watch.phpunit = {
				files: [ '**/*.php' ],
				tasks: [ 'phpunit:default' ]
			};
		}

		grunt.task.run( '_' + this.nameArgs );
	} );

	grunt.registerTask( 'precommit:image', [
		'imagemin:core'
	] );

	grunt.registerTask( 'precommit:js', [
		'webpack:prod',
		'jshint:corejs',
		'typecheck:js',
		'uglify:imgareaselect',
		'uglify:jqueryform',
		'uglify:moment',
		'qunit:compiled'
	] );

	grunt.registerTask( 'precommit:css', [
		'postcss:core'
	] );

	grunt.registerTask( 'precommit:php', [
		'phpstan',
		'phpunit'
	] );

	grunt.registerTask(
		'verify:emoji-markers',
		'Fails unless the generated emoji array region appears exactly once in its data file.',
		function() {
			var regions, found;

			if ( ! grunt.file.exists( EMOJI_ARRAYS_FILE ) ) {
				grunt.fatal( 'The emoji data file is missing: ' + EMOJI_ARRAYS_FILE );
			}

			regions = grunt.file.read( EMOJI_ARRAYS_FILE ).match( emojiArraysRegionRegExp() );
			found = null === regions ? 0 : regions.length;

			/*
			 * The region count is taken before the replacement runs, because
			 * `replace:emoji-regex` cannot report either failure itself. With no
			 * region it matches nothing and rewrites nothing; with two regions it
			 * rewrites both, and fetches the Twemoji file list once per region.
			 * This gate turns each case into a failure that names the file and the
			 * number of regions found.
			 */
			if ( 1 !== found ) {
				grunt.fatal(
					'Expected exactly one `' + EMOJI_ARRAYS_START + '` to `' + EMOJI_ARRAYS_END +
					'` region in ' + EMOJI_ARRAYS_FILE + ', found ' + found + '. ' +
					'replace:emoji-regex locates the arrays it regenerates by those comments, so it cannot run until exactly one region exists.'
				);
			}

			grunt.verbose.writeln( 'Found one emoji array region in ' + EMOJI_ARRAYS_FILE + '.' );
		}
	);

	/*
	 * Registered under its historical colon separated name, rather than as a target of
	 * the `replace` multitask, because the publication is no longer grunt-replace's to
	 * do: that plugin writes its destination in place, and the destination here is a
	 * tracked file. Grunt resolves the full colon separated name before it looks for a
	 * multitask target, which is what keeps `precommit:emoji`, `precommit` and the watch
	 * task that queues them working unchanged - the same arrangement as
	 * `replace:workflow-references-local-to-remote` below.
	 */
	grunt.registerTask(
		'replace:emoji-regex',
		'Regenerates the emoji arrays in their data file from the published Twemoji file list.',
		function() {
			publishEmojiArrays( renderEmojiArrays() );
		}
	);

	grunt.registerTask( 'precommit:emoji', [
		'verify:emoji-markers',
		'replace:emoji-regex'
	] );

	grunt.registerTask( 'precommit', 'Runs test and build tasks in preparation for a commit', function() {
		var done = this.async();
		var map = {
			svn: 'svn status --ignore-externals',
			git: 'git status --short'
		};

		find( [
			__dirname + '/.svn',
			__dirname + '/.git',
			path.dirname( __dirname ) + '/.svn'
		] );

		function find( set ) {
			var dir;

			if ( set.length ) {
				fs.stat( dir = set.shift(), function( error ) {
					error ? find( set ) : run( path.basename( dir ).substr( 1 ) );
				} );
			} else {
				runAllTasks();
			}
		}

		function runAllTasks() {
			grunt.log.writeln( 'Cannot determine which files are modified as SVN and GIT are not available.' );
			grunt.log.writeln( 'Running all tasks and all tests.' );
			grunt.task.run([
				'format:php',
				'precommit:js',
				'precommit:css',
				'precommit:image',
				'precommit:emoji',
				'precommit:php'
			]);

			done();
		}

		function run( type ) {
			var command = map[ type ].split( ' ' );

			grunt.util.spawn( {
				cmd: command.shift(),
				args: command
			}, function( error, result, code ) {
				var taskList = [];

				// Callback for finding modified paths.
				function testPath( path ) {
					var regex = new RegExp( ' ' + path + '$', 'm' );
					return regex.test( result.stdout );
				}

				// Callback for finding modified files by extension.
				function testExtension( extension ) {
					var regex = new RegExp( '\.' + extension + '$', 'm' );
					return regex.test( result.stdout );
				}

				if ( code === 0 ) {
					if ( [ 'package.json', 'Gruntfile.js', 'composer.json' ].some( testPath ) ) {
						grunt.log.writeln( 'Configuration files modified. Running `prerelease`.' );
						taskList.push( 'prerelease' );
					} else {
						if ( [ 'png', 'jpg', 'gif', 'jpeg' ].some( testExtension ) ) {
							grunt.log.writeln( 'Image files modified. Minifying.' );
							taskList.push( 'precommit:image' );
						}

						[ 'js', 'css', 'php' ].forEach( function( extension ) {
							if ( testExtension( extension ) ) {
								grunt.log.writeln( extension.toUpperCase() + ' files modified. ' + extension.toUpperCase() + ' tests will be run.' );
								taskList.push( 'precommit:' + extension );
							}
						} );

						if ( [ 'twemoji.js' ].some( testPath ) ) {
							grunt.log.writeln( 'twemoji.js has updated. Running `precommit:emoji.' );
							taskList.push( 'precommit:emoji' );
						}

						if ( testExtension( 'php' ) ) {
							grunt.log.writeln( 'PHP files modified. Code formatting will be run.' );
							var PHPfiles = result.stdout.split( '\n' );

							// Find .php files that have been modified or added.
							PHPfiles = PHPfiles.filter( function( file ) {
								return /^\s*[MA]\s*.*\.php$/.test( file );
							} );

							PHPfiles = PHPfiles.map( function( file ) {
								return file.replace( /^\s*[MA]\s*/, '' );
							} );

							changedFiles = {
								php: PHPfiles
							};

							taskList.push( 'format:php' );
						}
					}

					grunt.task.run( taskList );
					done();
				} else {
					runAllTasks();
				}
			} );
		}
	} );

	grunt.registerTask( 'copy:js', [
		'copy:npm-packages',
		'copy:vendor-js',
		'copy:admin-js',
		'copy:includes-js'
	] );

	grunt.registerTask( 'uglify:all', [
		'uglify:core',
		'uglify:emoji-loader',
		'uglify:jquery-ui',
		'uglify:imgareaselect',
		'uglify:jqueryform',
		'uglify:moment'
	] );

	grunt.registerTask( 'build:codemirror', [
		'webpack:codemirror',
		'cssmin:codemirror',
		'usebanner:codemirror',
		'copy:codemirror'
	] );

	grunt.registerTask( 'build:webpack', [
		'clean:webpack-assets',
		'webpack:prod',
		'webpack:dev',
		'clean:interactivity-assets',
	] );

	grunt.registerTask( 'build:js', [
		'clean:js',
		'build:webpack',
		'copy:js',
		'file_append',
		'uglify:all',
		'concat:tinymce',
		'concat:emoji'
	] );

	grunt.registerTask( 'build:css', [
		'clean:css',
		'copy:wp-admin-css-compat-rtl',
		'copy:wp-admin-css-compat-min',
		'cssmin:core',
		'colors',
		'rtl',
		'cssmin:rtl',
		'cssmin:colors',
		'cssmin:themes',
		'usebanner:files'
	] );

	grunt.registerTask( 'certificates:upgrade-package', 'Upgrades the package responsible for supplying the certificate authority certificate store bundled with WordPress.', function() {
		var done = this.async();
		var flags = this.flags;
		var spawn = require( 'child_process' ).spawnSync;
		var fs = require( 'fs' );

		// Ensure that `composer update` has been run and the dependency is installed.
		if ( ! fs.existsSync( 'vendor' ) || ! fs.existsSync( 'vendor/composer' ) || ! fs.existsSync( 'vendor/composer/ca-bundle' ) ) {
			grunt.log.error( 'composer/ca-bundle dependency is missing. Please run `composer update` before attempting to upgrade the certificate bundle.' );
			done( false );
			return;
		}

		/*
		 * Because the `composer/ca-bundle` is pinned to an exact version to ensure upgrades are applied intentionally,
		 * the `composer update` command will not upgrade the dependency. Instead, `composer require` must be called,
		 * but the specific version being upgraded to must be known and passed to the command.
		 */
		var outdatedResult = spawn( 'composer', [ 'outdated', 'composer/ca-bundle', '--format=json' ] );

		if ( outdatedResult.status !== 0 ) {
			grunt.log.error( 'Failed to get the package information for composer/ca-bundle.' );
			done( false );
			return;
		}

		var packageInfo;
		try {
			var stdout = outdatedResult.stdout.toString().trim();
			if ( ! stdout ) {
				grunt.log.writeln( 'The latest version is already installed.' );
				done( true );
				return;
			}
			packageInfo = JSON.parse( stdout );
		} catch ( e ) {
			grunt.log.error( 'Failed to parse the package information for composer/ca-bundle.' );
			done( false );
			return;
		}

		// Check for the version information needed to perform the necessary comparisons.
		if ( ! packageInfo.versions || ! packageInfo.versions[0] || ! packageInfo.latest ) {
			grunt.log.error( 'Could not determine version information for composer/ca-bundle.' );
			done( false );
			return;
		}

		var currentVersion = packageInfo.versions[0];
		var latestVersion = packageInfo.latest;

		// Compare versions to ensure we actually need to update
		if ( currentVersion === latestVersion ) {
			grunt.log.writeln( 'The latest version is already installed: ' + latestVersion + '.' );
			done( true );
			return;
		}

		grunt.log.writeln( 'Installed version: ' + currentVersion );
		grunt.log.writeln( 'New version found: ' + latestVersion );

		// Upgrade to the latest version and change the pinned version in composer.json.
		var args = [ 'require', 'composer/ca-bundle:' + latestVersion, '--dev' ];

		grunt.util.spawn( {
			cmd: 'composer',
			args: args,
			opts: { stdio: 'inherit' }
		}, function( error ) {
			if ( flags.error && error ) {
				done( false );
			} else {
				grunt.log.writeln( 'Successfully updated composer/ca-bundle to ' + latestVersion );
				done( true );
			}
		} );
	} );

	grunt.registerTask( 'build:certificates', [
		'copy:certificates'
	] );

	grunt.registerTask( 'certificates:upgrade', [
		'certificates:upgrade-package',
		'copy:certificates'
	] );

	grunt.registerTask( 'build:autoload-classmap', 'Regenerates the core autoloader class map from the source tree.', function() {
		var done   = this.async(),
			crypto = require( 'crypto' ),
			file   = `${ SOURCE_DIR }wp-includes/autoload-classmap.php`;

		/*
		 * The generator inspects every candidate file with PHP's own tokenizer, so it
		 * is written in PHP and spawned here. It rewrites SOURCE_DIR in place and is
		 * therefore sequenced ahead of build:files, which copies that result into
		 * BUILD_DIR.
		 *
		 * Its output is captured rather than inherited, because the last line it
		 * prints is a digest of the map it rendered. That digest is what lets this
		 * task accept the file on disk only when the file *is* that map: a nonzero
		 * exit, a run that stopped before publishing, a partially written file or a
		 * stale map left by an earlier interrupted run all have to fail the build
		 * rather than be copied into BUILD_DIR and shipped.
		 */
		grunt.util.spawn( {
			cmd: 'php',
			args: [ 'tools/build/generate-autoload-classmap.php', SOURCE_DIR ]
		}, function( error, result ) {
			var digest, published, entries, lint;

			if ( result && result.stdout ) {
				grunt.log.writeln( result.stdout );
			}

			if ( result && result.stderr ) {
				grunt.log.error( result.stderr );
			}

			if ( error ) {
				grunt.log.error( `The autoload class map generator failed; refusing to accept the class map at ${ file }.` );
				done( false );
				return;
			}

			digest = /^AUTOLOAD_CLASSMAP_DIGEST entries=(\d+) bytes=(\d+) sha256=([0-9a-f]{64})$/m.exec(
				result && result.stdout ? String( result.stdout ) : ''
			);

			/*
			 * The generator prints the digest last, once it has published the map and
			 * read it back, so its absence means the map was never published.
			 */
			if ( null === digest ) {
				grunt.log.error( `The autoload class map generator reported no digest; refusing to accept the class map at ${ file }.` );
				done( false );
				return;
			}

			/*
			 * An entry less map would switch the core autoloader off while still
			 * looking like a legitimate build result, and copy:files would then ship
			 * it. Fail the task instead of accepting it.
			 */
			if ( 0 === parseInt( digest[ 1 ], 10 ) ) {
				grunt.log.error( `No core classes were found; refusing to accept an empty autoload class map at ${ file }.` );
				done( false );
				return;
			}

			try {
				published = fs.readFileSync( file );
			} catch ( readError ) {
				grunt.log.error( `The autoload class map at ${ file } could not be read back: ${ readError.message }` );
				done( false );
				return;
			}

			// Byte for byte, so that nothing but the generated map can pass from here.
			if ( published.length !== parseInt( digest[ 2 ], 10 ) ||
				crypto.createHash( 'sha256' ).update( published ).digest( 'hex' ) !== digest[ 3 ]
			) {
				grunt.log.error( `The autoload class map at ${ file } is not the map that was generated; refusing to accept it.` );
				done( false );
				return;
			}

			// Checked independently of the digest, because the entry count is the one thing the autoloader needs the file to hold.
			entries = published.toString( 'utf8' ).match( /^\t'[^']+' => '[^']+',$/gm );

			if ( null === entries || entries.length !== parseInt( digest[ 1 ], 10 ) ) {
				grunt.log.error( `The autoload class map at ${ file } holds ${ null === entries ? 0 : entries.length } entries where the generator rendered ${ digest[ 1 ] }; refusing to accept it.` );
				done( false );
				return;
			}

			// A map the PHP parser rejects would turn the first autoload attempt into a fatal error.
			lint = spawn( 'php', [ '-l', file ], { encoding: 'utf8' } );

			if ( 0 !== lint.status ) {
				grunt.log.error( `${ lint.stdout || '' }${ lint.stderr || '' }` );
				grunt.log.error( `The autoload class map at ${ file } is not valid PHP; refusing to accept it.` );
				done( false );
				return;
			}

			grunt.log.writeln( `Verified ${ entries.length } autoload class map entries in ${ file }.` );
			done( true );
		} );
	} );

	/**
	 * Keeps the class map current during a watch session.
	 *
	 * The full build regenerates the map once, ahead of every task that consumes it.
	 * A watch session then runs for hours over the same tree, and its `all` target
	 * only cleans and copies the file that changed, so a class added, renamed, moved
	 * or deleted after the session started left the map describing a tree that no
	 * longer exists: the autoloader would answer a name with a path that has moved,
	 * or fail to answer one that now exists, for as long as the session ran.
	 *
	 * Queued ahead of `clean:dynamic` and `copy:dynamic` rather than after them, so
	 * that the map is regenerated before the same copy carries it into the build
	 * tree, which is where a non `--dev` session is served from. Under `--dev` the
	 * source tree is served directly and `copy:dynamic` is not configured, so
	 * rewriting the map in place is all that is needed.
	 *
	 * It does nothing at all unless a PHP file the map can be built from changed,
	 * which is what keeps an edit to a stylesheet or an image from spending the
	 * generator's time. The generated map is excluded from the watched files above
	 * and from what the handler records, so the rewrite cannot become the next change
	 * to react to.
	 */
	grunt.registerTask(
		'build:autoload-classmap:dynamic',
		'Regenerates the autoloader class map when a watched PHP file has changed.',
		function() {
			var changed = watchedPhpChanges,
				src;

			// Cleared before anything can fail, so one failure is not reported on every later change.
			watchedPhpChanges = [];

			if ( 0 === changed.length ) {
				grunt.verbose.writeln( 'No watched PHP file changed; leaving the autoload class map as it is.' );

				return;
			}

			grunt.log.writeln( 'PHP changed (' + changed.join( ', ' ) + '); regenerating the autoload class map.' );

			if ( ! grunt.option( 'dev' ) ) {
				src = grunt.config( [ 'copy', 'dynamic', 'src' ] ) || [];

				if ( -1 === src.indexOf( AUTOLOAD_CLASSMAP_RELATIVE ) ) {
					grunt.config( [ 'copy', 'dynamic', 'src' ], src.concat( [ AUTOLOAD_CLASSMAP_RELATIVE ] ) );
				}
			}

			/*
			 * Queued rather than called, so that the one task that regenerates the map
			 * also verifies it here: a generator failure, a missing digest, an empty map
			 * or a map the PHP parser rejects fails the watch cycle instead of being
			 * copied into the tree the session serves.
			 */
			grunt.task.run( 'build:autoload-classmap' );
		}
	);

	grunt.registerTask( 'build:files', [
		'clean:files',
		'copy:files',
		'copy:version',
	] );

	grunt.registerTask( 'replace:workflow-references-local-to-remote', [
		'copy:workflow-references-local-to-remote',
	]);

	grunt.registerTask( 'replace:workflow-references-remote-to-local', [
		'copy:workflow-references-remote-to-local',
	]);

	grunt.registerTask( 'post-branching', [
		'clean:workflows',
		'replace:workflow-references-local-to-remote'
	]);

	/**
	 * Build verification tasks.
	 */
	grunt.registerTask( 'verify:build', [
		'verify:old-files',
		'verify:source-maps',
		'verify:build-guards',
	] );

	/**
	 * Build assertions for the tasks that refuse a generated artifact.
	 *
	 * `build:autoload-classmap` and `verify:emoji-markers` exist to reject a result rather
	 * than to produce one, so neither is observable from a build that goes well: an empty,
	 * truncated or stale class map, and an emoji data file with no marker region or with
	 * two, all look like ordinary build results. The cases in `tests/build/` run both
	 * tasks against sandbox source trees that hold those results on purpose and assert the
	 * refusals, which is the only way those branches are ever executed.
	 *
	 * Run with Node's own test runner, so this adds no dependency and no configuration.
	 *
	 * @since 7.0.0
	 */
	grunt.registerTask(
		'verify:build-guards',
		'Runs the task level guards for the generated build artifacts.',
		function() {
			grunt.util.spawn( {
				cmd: process.execPath,
				args: [ '--test', 'tests/build/' ],
				opts: { stdio: 'inherit' }
			}, function( error, result, code ) {
				if ( 0 !== code ) {
					grunt.log.error( 'The build task guards in tests/build/ did not pass.' );
				}

				this( 0 === code );
			}.bind( this.async() ) );
		}
	);

	/**
	 * Build assertions to ensure no project files are inside `$_old_files` in the build directory.
	 *
	 * @ticket 36083
	 */
	grunt.registerTask( 'verify:old-files', function() {
		const file = `${ BUILD_DIR }wp-admin/includes/update-core.php`;

		assert(
			fs.existsSync( file ),
			'The build/wp-admin/includes/update-core.php file does not exist.'
		);

		const contents = fs.readFileSync( file, {
			encoding: 'utf8',
		} );

		assert(
			contents.length > 0,
			'The build/wp-admin/includes/update-core.php file must not be empty.'
		);

		const match = contents.match( /\$_old_files = array\(([^\)]+)\);/ );

		assert(
			match.length > 0,
			'The build/wp-admin/includes/update-core.php file does not include an `$_old_files` array.'
		);

		const files = match[1].split( '\n\t' ).filter( function( file ) {
			// Filter out empty lines.
			if ( '' === file ) {
				return false;
			}

			// Filter out commented out lines.
			if ( 0 === file.indexOf( '/' ) ) {
				return false;
			}

			return true;
		} ).map( function( file ) {
			// Strip leading and trailing single quotes and commas.
			return file.replace( /^\'|\',$/g, '' );
		} );

		files.forEach(function( file ){
			const search = `${ BUILD_DIR }${ file }`;
			assert(
				false === fs.existsSync( search ),
				`${ search } should not be present in the $_old_files array.`
			);
		});
	} );

	/**
	 * Compiled JavaScript files may link to sourcemaps. In some cases,
	 * the source map may not be available, which can cause 404 errors when
	 * browsers try to download the sourcemap from the referenced URLs.
	 * Ensure that sourcemap links are not included in JavaScript files.
	 *
	 * @ticket 24994
	 * @ticket 46218
	 * @ticket 60348
	 */
	grunt.registerTask( 'verify:source-maps', function() {
		const ignoredFiles = [
			'build/wp-includes/js/dist/components.js',
			'build/wp-includes/js/dist/data.js',
		];
		const files = buildFiles.reduce( ( acc, path ) => {
			// Skip excluded paths and any path that isn't a file.
			if ( '!' === path[0] || '**' !== path.substr( -2 ) ) {
				return acc;
			}
			acc.push( ...glob.sync( `${ BUILD_DIR }/${ path }/*.js` ) );
			return acc;
		}, [] );

		assert(
			files.length > 0,
			'No JavaScript files found in the build directory.'
		);

		files
			.filter(file => ! ignoredFiles.includes( file) )
			.forEach( function( file ) {
				const contents = fs.readFileSync( file, {
					encoding: 'utf8',
				} );
				// `data:` URLs are allowed:
				const doesNotHaveSourceMap = ! /^\/\/# sourceMappingURL=((?!data:).)/m.test(contents);

				assert(
					doesNotHaveSourceMap,
					`The ${ file } file must not contain a sourceMappingURL.`
				);
			} );
	} );

	grunt.registerTask( 'build:gutenberg', [
		'copy:gutenberg-php',
		'copy:gutenberg-js',
		'gutenberg:copy',
		'copy:gutenberg-modules',
		'copy:gutenberg-styles',
		'copy:gutenberg-theme-json',
		'copy:gutenberg-icons',
	] );

	grunt.registerTask( 'build', function() {
		if ( grunt.option( 'dev' ) ) {
			grunt.task.run( [
				'gutenberg:verify',
				'build:autoload-classmap',
				'build:js',
				'build:css',
				'build:codemirror',
				'build:gutenberg',
				'copy-vendor-scripts',
				'build:certificates'
			] );
		} else {
			grunt.task.run( [
				'gutenberg:verify',
				'build:certificates',
				// Generated before `build:files`, so that `copy:files` carries it into the build.
				'build:autoload-classmap',
				'build:files',
				'build:js',
				'build:css',
				'build:codemirror',
				'build:gutenberg',
				'copy-vendor-scripts',
				'replace:source-maps',
				'verify:build'
			] );
		}
	} );

	grunt.registerTask( 'prerelease', [
		'format:php:error',
		'precommit:php',
		'precommit:js',
		'precommit:css',
		'precommit:image'
	] );

	// Testing tasks.
	grunt.registerMultiTask( 'phpunit', 'Runs PHPUnit tests, including the ajax, external-http, and multisite tests.', function() {
		var args = phpUnitWatchGroup ? this.data.args.concat( [ '--group', phpUnitWatchGroup ] ) : this.data.args;

		args.unshift( 'test', '--' );

		grunt.util.spawn({
			cmd: 'composer',
			args: args,
			opts: { stdio: 'inherit' }
		}, this.async());
	});

	grunt.registerTask( 'qunit:compiled', 'Runs QUnit tests on compiled as well as uncompiled scripts.',
		['build', 'copy:qunit', 'qunit']
	);

	grunt.registerTask( 'test', 'Runs all QUnit and PHPUnit tasks.', ['qunit:compiled', 'phpunit'] );

	grunt.registerTask( 'typecheck:js', 'Runs TypeScript type checking.', function() {
		var done = this.async();

		grunt.util.spawn( {
			cmd: 'npm',
			args: [ 'run', 'typecheck:js' ],
			opts: { stdio: 'inherit' }
		}, function( error ) {
			done( ! error );
		} );
	} );

	grunt.registerTask( 'phpstan', 'Runs PHPStan on the entire codebase.', function() {
		var done = this.async();

		grunt.util.spawn( {
			cmd: 'composer',
			args: [ 'phpstan' ],
			opts: { stdio: 'inherit' }
		}, function( error ) {
			done( ! error );
		} );
	} );

	grunt.registerTask( 'format:php', 'Runs the code formatter on changed files.', function() {
		var done = this.async();
		var flags = this.flags;
		var args = changedFiles.php;

		args.unshift( 'format' );

		grunt.util.spawn( {
			cmd: 'composer',
			args: args,
			opts: { stdio: 'inherit' }
		}, function( error ) {
			if ( flags.error && error ) {
				done( false );
			} else {
				done( true );
			}
		} );
	} );

	grunt.registerTask( 'lint:php', 'Runs the code linter on changed files.', function() {
		var done = this.async();
		var flags = this.flags;
		var args = changedFiles.php;

		args.unshift( 'lint' );

		grunt.util.spawn( {
			cmd: 'composer',
			args: args,
			opts: { stdio: 'inherit' }
		}, function( error ) {
			if ( flags.error && error ) {
				done( false );
			} else {
				done( true );
			}
		} );
	} );

	grunt.registerTask( 'wp-packages:update', 'Update WordPress packages', function() {
		const distTag = grunt.option('dist-tag') || 'latest';
		grunt.log.writeln( `Updating WordPress packages (--dist-tag=${distTag})` );
		spawn( 'npx', [ 'wp-scripts', 'packages-update', `--dist-tag=${distTag}` ], {
			cwd: __dirname,
			stdio: 'inherit',
		} );
	} );

	grunt.registerTask( 'browserslist:update', 'Update the local database of browser supports', function() {
		grunt.log.writeln( `Updating browsers list` );
		spawn( 'npx', [ 'browserslist@latest', '--update-db' ], {
			cwd: __dirname,
			stdio: 'inherit',
		} );
	} );

	grunt.registerTask( 'wp-packages:refresh-deps', 'Update version of dependencies in package.json to match the ones listed in the latest WordPress packages', function() {
		const distTag = grunt.option('dist-tag') || 'latest';
		grunt.log.writeln( `Updating versions of dependencies listed in package.json (--dist-tag=${distTag})` );
		spawn( 'node', [ 'tools/release/sync-gutenberg-packages.js', `--dist-tag=${distTag}` ], {
			cwd: __dirname,
			stdio: 'inherit',
		} );
	} );

	grunt.registerTask( 'wp-packages:sync-stable-blocks', 'Refresh the PHP files referring to stable @wordpress/block-library blocks.', function() {
		grunt.log.writeln( `Syncing stable blocks from @wordpress/block-library to src/` );
		const { main } = require( './tools/release/sync-stable-blocks' );
		main();
	} );

	// Patch task.
	grunt.renameTask('patch_wordpress', 'patch');

	// Add an alias `apply` of the `patch` task name.
	grunt.registerTask('apply', 'patch');

	// Default task.
	grunt.registerTask('default', ['build']);

	/*
	 * Automatically updates the `:dynamic` configurations
	 * so that only the changed files are updated.
	 */
	grunt.event.on( 'watch', function( action, filepath, target ) {
		var src;

		// Only configure the dynamic tasks based on known targets.
		if ( [ 'all', 'rtl', 'webpack', 'js-enqueues', 'js-webpack' ].indexOf( target ) === -1 ) {
			return;
		}

		// Normalize filepath for Windows.
		filepath = filepath.replace( /\\/g, '/' );

		// If the target is a file in the restructured js src.
		if ( target === 'js-enqueues' ) {
			var files = {};
			var configs, dest;

			// If it's a vendor file which are configured with glob matchers.
			if ( filepath.indexOf( SOURCE_DIR + 'js/_enqueues/vendor/' ) === 0 ) {
				// Grab the glob matchers from the copy task.
				configs = grunt.config( [ 'copy', 'vendor-js', 'files' ] );

				// For each glob matcher check if it matches and if so set the variables for our dynamic tasks.
				for ( var i = 0; i < configs.length; i++ ) {
					var config = configs[ i ];
					var relative = path.relative( config.cwd, filepath );
					var minimatch = require('minimatch');

					if ( minimatch.match( config.src, relative, {} ) ) {
						dest = config.dest + relative;
						src = [ path.relative( WORKING_DIR, dest ) ];
						files[ dest ] = [ filepath ];
						break;
					}
				}
			// Or if it's another file which has a straight mapping.
			} else {
				configs = Object.assign( {},
					grunt.config( [ 'copy', 'admin-js', 'files' ] ),
					grunt.config( [ 'copy', 'includes-js', 'files' ] )
				);

				for ( dest in configs ) {
					// If a file in the mapping matches then set the variables for our dynamic tasks.
					if ( dest && configs.hasOwnProperty( dest ) && configs[ dest ][0] === './' + filepath ) {
						files[ dest ] = configs[ dest ];
						src = [ path.relative( WORKING_DIR, dest ) ];
						break;
					}
				}
			}

			// Configure our dynamic-js copy task which uses a file mapping rather than simply copying from src to build.
			if ( action !== 'deleted' ) {
				grunt.config( [ 'copy', 'dynamic-js', 'files' ], files );
			}
		// For the webpack builds configure the task to only check those files built by webpack.
		} else if ( target === 'js-webpack' ) {
			src = [
				'wp-includes/js/media-audiovideo.js',
				'wp-includes/js/media-grid.js',
				'wp-includes/js/media-models.js',
				'wp-includes/js/media-views.js'
			];
		// Else simply use the path relative to the source directory.
		} else {
			src = [ path.relative( SOURCE_DIR, filepath ) ];

			/*
			 * Recorded for `build:autoload-classmap:dynamic`, which runs first among this
			 * target's tasks. The class map is generated from the source tree, so a PHP
			 * file that changes during a long watch session can add, move or remove a
			 * mapped class; without this the map would keep describing the tree as it was
			 * when the session started, and the autoloader would answer a stale path.
			 *
			 * Only PHP outside `wp-content` is recorded. The map covers `wp-includes` and
			 * two `wp-admin` files, and the bootstrap closure it is checked against is
			 * walked from `wp-settings.php`, so a theme template or an uploaded file
			 * cannot change it - and regenerating on one would spend the generator's time
			 * on every keystroke in a template.
			 */
			if ( 'all' === target &&
				'.php' === path.extname( filepath ) &&
				0 !== src[ 0 ].indexOf( 'wp-content/' ) &&
				AUTOLOAD_CLASSMAP_RELATIVE !== src[ 0 ] &&
				-1 === watchedPhpChanges.indexOf( src[ 0 ] )
			) {
				watchedPhpChanges.push( src[ 0 ] );
			}
		}

		if ( ! src ) {
			grunt.warn( 'Failed to determine the destination file.' );
			return;
		}

		if ( action === 'deleted' ) {
			// Clean up only those files that were deleted.
			grunt.config( [ 'clean', 'dynamic', 'src' ], src );
		} else {
			if ( ! grunt.option( 'dev' ) ) {
				// Otherwise copy over only the changed file.
				grunt.config(['copy', 'dynamic', 'src'], src);
			}

			// For javascript also minify and validate the changed file.
			if ( target === 'js-enqueues' ) {
				grunt.config( [ 'uglify', 'dynamic', 'src' ], src );
				grunt.config( [ 'dynamic', 'files', 'src' ], src.map( function( dir ) { return  WORKING_DIR + dir; } ) );
			}
			// For webpack only validate the file, minification is handled by webpack itself.
			if ( target === 'js-webpack' ) {
				grunt.config( [ 'dynamic', 'files', 'src' ], src.map( function( dir ) { return  WORKING_DIR + dir; } ) );
			}
			// For css run the rtl task on just the changed file.
			if ( target === 'rtl' ) {
				grunt.config( [ 'rtlcss', 'dynamic', 'src' ], src );
			}
		}
	});
};
