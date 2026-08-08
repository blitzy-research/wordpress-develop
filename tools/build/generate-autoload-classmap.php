<?php
/**
 * Generator for the core autoloader class map.
 *
 * Writes `src/wp-includes/autoload-classmap.php`, the generated manifest that
 * `src/wp-includes/autoload.php` resolves against. The map is never maintained by
 * hand: it is produced from the source tree by the `build:autoload-classmap` task
 * in Gruntfile.js, which runs this file with the PHP binary.
 *
 * Only a file that can be loaded on its own, at any point in a request, is
 * eligible. Three conditions establish that, and all three are required:
 *
 * 1. The file declares exactly one class, interface, trait or enum. A file that
 *    declares several symbols cannot be keyed by one name, and a file that
 *    declares none has nothing to autoload.
 *
 * 2. The file has no file scope side effect. Loading it must do nothing except
 *    declare that one symbol, because the autoloader loads it at an arbitrary
 *    moment that the file's author never anticipated. A file scope `require`, a
 *    function call, an assignment, a function declaration, a conditional or any
 *    inline output all disqualify it. This condition is what keeps
 *    `class-wp-customize-control.php` out of the map: it requires its own
 *    subclasses from its file tail, and autoloading a subclass would re-enter
 *    the parent's declaration through that tail and recurse until the process
 *    died.
 *
 * 3. Every name the symbol needs at compile time - its parent class, the
 *    interfaces it implements, the traits it uses - is itself resolvable. A name
 *    is resolvable when it is mapped here as well, when it is declared by a file
 *    the bootstrap always loads, when it belongs to a namespace served by an
 *    autoloader the bootstrap registers, or when PHP declares it internally.
 *    This condition is what keeps `wp_xmlrpc_server`, `WP_PHPMailer` and the
 *    SimplePie and Text_Diff subclasses out of the map: their parents live in
 *    bundled libraries that are loaded by an explicit `require` at the point of
 *    use, so autoloading the subclass on its own would fail with a fatal error
 *    where leaving it unmapped simply reports the name as undeclared, exactly as
 *    it did before an autoloader existed.
 *
 * Condition 3 is transitive, so it is applied repeatedly until the map stops
 * shrinking: dropping a parent has to drop everything that inherits from it.
 *
 * A fourth condition is enforced below rather than stated as eligibility, because
 * it is about the bootstrap rather than about the file: a file that
 * `wp-settings.php` reaches through top level requires is excluded, and the walk
 * that establishes which those are is transitive. Such a file has nothing to gain
 * from an entry, and an entry would make it unsafe. The bootstrap requires with
 * plain `require`, so a reference arriving before that line executes would
 * autoload the file and the `require` would then fatally redeclare the symbol.
 * The two admin Site Health files are the only opted in exceptions, and they
 * qualify because every include of them in the tree is a `require_once`.
 *
 * That condition couples this map to the bootstrap's require set by construction,
 * which is the property to preserve: whenever a require is added to or dropped
 * from `wp-settings.php`, this generator has to run again, and the class map is
 * only ever correct as the output of that run. It is why the map is never edited
 * by hand and why `build:autoload-classmap` is sequenced ahead of `build:files`.
 *
 * One consequence is worth stating plainly, because the map is easy to misread as
 * a list of files saved: an entry only keeps a file out of a request when nothing
 * else on that request loads it. The twenty default widget entries are the
 * clearest example. Each is eligible, and each is mapped because
 * `default-widgets.php` is required from inside `wp_widgets_init()` rather than at
 * the top level of the bootstrap, so it is outside the walk above and there is no
 * redeclaration to fear - it uses `require_once`. None of them is autoloaded on a
 * front-end or dashboard request all the same, because that `require_once` runs at
 * `init` before any of the names is referenced. They are kept because a reference
 * that arrives earlier than `init` has to resolve rather than fail, which is
 * coverage, and coverage is not a file saved.
 *
 * Keys are lower cased because PHP resolves class, interface and trait names
 * case insensitively, and `wp_autoload_class()` therefore lower cases the name
 * it is given before looking it up. Values keep the real, cased path.
 *
 * @package WordPress
 */

/**
 * Returns the directories under `wp-includes/` that the class map never covers.
 *
 * Each entry is a path relative to `wp-includes/`, and everything below it is
 * skipped. Three kinds of directory are listed:
 *
 * - Bundled third party libraries. They ship their own loading mechanism, and
 *   core must not start claiming ownership of names that belong to them.
 * - Generated or externally synchronised trees. Their contents are rewritten by
 *   tooling, so a class map entry pointing into them would go stale silently.
 *   `blocks`, `build` and `icons` are all written by `tools/gutenberg/copy.js`,
 *   which is also why the map has to be listed here rather than inferred from
 *   what happens to be in the tree: whether the map depends on synchronised
 *   state is what decides whether it regenerates identically in a fresh clone
 *   and in a fully built one, and the workflows compare those with
 *   `git diff --exit-code`.
 * - Trees that hold no PHP class at all.
 *
 * @return string[] Directory names relative to `wp-includes/`.
 */
function wp_autoload_classmap_excluded_directories() {
	return array(
		// Bundled third party libraries with their own loading mechanism.
		'ID3',
		'IXR',
		'PHPMailer',
		'Requests',
		'SimplePie',
		'Text',
		'php-ai-client',
		'sodium_compat',

		// Generated or externally synchronised trees.
		'blocks',
		'build',
		'icons',

		// Trees that hold no PHP class.
		'assets',
		'certificates',
		'css',
		'images',
		'js',
		'theme-compat',
	);
}

/**
 * Returns files outside `wp-includes/` that the class map covers.
 *
 * The bootstrap used to require these unconditionally, so the names they declare
 * were available on every request, including on the front end and in a plain CLI
 * bootstrap. They are mapped so that they stay resolvable now that the bootstrap
 * no longer parses them on requests that never use them.
 *
 * @return string[] Paths relative to the WordPress root.
 */
function wp_autoload_classmap_additional_files() {
	return array(
		'wp-admin/includes/class-wp-site-health.php',
		'wp-admin/includes/class-wp-site-health-auto-updates.php',
	);
}

/**
 * Returns the namespace prefixes served by autoloaders registered no later than
 * core's own autoloader.
 *
 * A name under one of these prefixes resolves at every moment the class map can be
 * consulted, so a core class may extend or implement it and still be safe to
 * autoload. Qualifying is a question of ordering rather than of whether the
 * bootstrap registers the library at all:
 *
 * - `wp-settings.php` requires `wp-includes/autoload.php`, and from that line on a
 *   mapped name can be asked for.
 * - `wp-includes/class-wp-http.php`, which calls `WpOrg\Requests\Autoload::register()`,
 *   and `wp-includes/php-ai-client/autoload.php`, which registers the
 *   `WordPress\AiClient\` and `WordPress\AiClientDependencies\` prefixes, are both
 *   required roughly 180 lines further down.
 * - `wp-settings.php` returns early when `SHORTINIT` is defined, ahead of both of
 *   them, so on that path neither library is registered at all.
 *
 * A mapped declaration whose parent, interface or trait lives under one of those
 * prefixes is therefore resolvable only in a fully bootstrapped request, and raises
 * a fatal error in every earlier context: a `SHORTINIT` bootstrap, an
 * `object-cache.php` or `advanced-cache.php` drop-in, or any code reached between
 * the two points above. Such a declaration belongs in the eager bootstrap next to
 * the autoloader it depends on, not in the map. No prefix in the tree meets the
 * ordering requirement, so the list is deliberately empty; a library whose
 * autoloader is registered lazily at the point of use, such as SimplePie, could not
 * meet it either.
 *
 * @return string[] Namespace prefixes, each ending in a backslash.
 */
function wp_autoload_classmap_autoloaded_namespaces() {
	return array();
}

/**
 * Returns the type names that never denote a class, interface or trait.
 *
 * Collected from declaration headers alongside real names - an enum backing type
 * and the relative class keywords read the same way to the tokenizer - and
 * discarded, so that they are never mistaken for an unresolvable parent.
 *
 * @return string[] Lower cased reserved names.
 */
function wp_autoload_classmap_reserved_type_names() {
	return array(
		'array',
		'bool',
		'callable',
		'false',
		'float',
		'int',
		'iterable',
		'mixed',
		'never',
		'null',
		'object',
		'parent',
		'self',
		'static',
		'string',
		'true',
		'void',
	);
}

/**
 * Returns the token id this generator uses for a name written with a namespace separator.
 *
 * Deliberately not one of PHP's own ids. PHP 8.0 reports `Foo\Bar` as a single
 * T_NAME_QUALIFIED token, while PHP 7.4 - the floor `composer.json` declares -
 * reports it as a run of T_STRING and T_NS_SEPARATOR tokens. Both shapes are
 * folded onto this id by wp_autoload_classmap_normalize_tokens() so that the rest
 * of the generator recognises one shape rather than two, and the id is negative
 * so it can never collide with a real token id, which `token_get_all()` always
 * reports as a positive integer.
 *
 * @return int Token id for a namespace qualified name.
 */
function wp_autoload_classmap_name_token() {
	return -1;
}

/**
 * Returns the ids PHP 8.0 and later report a namespace qualified name with.
 *
 * Each constant is read through `constant()` after `defined()` rather than being
 * named directly, because none of them exists on PHP 7.4: naming one there would
 * raise "Use of undefined constant" and evaluate to its own name, which would
 * silently stop matching any token, and naming one on PHP 8 in a file that also
 * has to run on 7.4 is exactly the coupling this indirection removes. An empty
 * result therefore means the interpreter predates them and only the 7.4 shape can
 * appear in its token stream.
 *
 * @return int[] Token ids, for those the running PHP declares.
 */
function wp_autoload_classmap_qualified_name_tokens() {
	static $tokens = null;

	if ( null !== $tokens ) {
		return $tokens;
	}

	$tokens = array();

	foreach ( array( 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE' ) as $constant ) {
		if ( defined( $constant ) ) {
			$tokens[] = constant( $constant );
		}
	}

	return $tokens;
}

/**
 * Returns the token ids that carry a class, interface or trait name.
 *
 * A name of one segment stays a plain T_STRING on every version, and anything
 * written with a namespace separator arrives normalized onto the generator's own
 * id, so these two are the whole set.
 *
 * @return int[] Token ids that hold a name.
 */
function wp_autoload_classmap_name_tokens() {
	return array( T_STRING, wp_autoload_classmap_name_token() );
}

/**
 * Determines whether a name was written fully qualified.
 *
 * Read from the text rather than from the token id, because the text is the one
 * thing both tokenizer shapes agree on: PHP 8 keeps the leading separator in the
 * token it reports, and the normalizer keeps it when it folds the 7.4 run.
 *
 * @param string $name Name as it appears in the source.
 * @return bool Whether the name begins with a namespace separator.
 */
function wp_autoload_classmap_is_absolute_name( $name ) {
	return '' !== $name && '\\' === $name[0];
}

/**
 * Folds a namespace qualified name into one token, whatever version tokenized it.
 *
 * PHP 8.0 reports `Foo\Bar`, `\Foo\Bar` and `namespace\Bar` as one token each.
 * PHP 7.4 reports the same names as a contiguous run of T_STRING, T_NS_SEPARATOR
 * and, for the relative form, T_NAMESPACE tokens, with nothing between the pieces
 * because a namespace separator may not be surrounded by whitespace. This walks a
 * token list once and emits the PHP 8 shape on both: one token, carrying the whole
 * name as its text and the line the name started on.
 *
 * Applied to every token list the generator inspects, so that the map produced on
 * the declared PHP 7.4 floor is the same map produced on PHP 8.
 *
 * @param array $tokens Token list from token_get_all().
 * @return array Token list in which every qualified name is a single token.
 */
function wp_autoload_classmap_normalize_tokens( $tokens ) {
	$qualified  = wp_autoload_classmap_qualified_name_tokens();
	$name_token = wp_autoload_classmap_name_token();
	$total      = count( $tokens );
	$normalized = array();

	for ( $index = 0; $index < $total; $index++ ) {
		$token = $tokens[ $index ];

		if ( ! is_array( $token ) ) {
			$normalized[] = $token;
			continue;
		}

		// Already one token: only its id has to change.
		if ( in_array( $token[0], $qualified, true ) ) {
			$normalized[] = array( $name_token, $token[1], isset( $token[2] ) ? $token[2] : 0 );
			continue;
		}

		if ( T_STRING !== $token[0] && T_NS_SEPARATOR !== $token[0] && T_NAMESPACE !== $token[0] ) {
			$normalized[] = $token;
			continue;
		}

		/*
		 * `namespace` is a name segment only in the relative form, where a separator
		 * follows it immediately. Everywhere else it opens a namespace statement,
		 * which the caller has to keep seeing as T_NAMESPACE.
		 */
		if ( T_NAMESPACE === $token[0]
			&& ( ! isset( $tokens[ $index + 1 ] )
				|| ! is_array( $tokens[ $index + 1 ] )
				|| T_NS_SEPARATOR !== $tokens[ $index + 1 ][0] )
		) {
			$normalized[] = $token;
			continue;
		}

		/*
		 * A name alternates between a segment and a separator, so the run continues
		 * only while that alternation holds. Stopping at the first token that breaks
		 * it is what keeps `Foo::bar` and `Foo bar` from being read as one name.
		 */
		$name     = $token[1];
		$line     = isset( $token[2] ) ? $token[2] : 0;
		$expects  = T_NS_SEPARATOR === $token[0] ? T_STRING : T_NS_SEPARATOR;
		$consumed = $index;

		for ( $next = $index + 1; $next < $total; $next++ ) {
			if ( ! is_array( $tokens[ $next ] ) || $expects !== $tokens[ $next ][0] ) {
				break;
			}

			$name    .= $tokens[ $next ][1];
			$expects  = T_NS_SEPARATOR === $expects ? T_STRING : T_NS_SEPARATOR;
			$consumed = $next;
		}

		// A single segment with no separator is the plain name token it already was.
		if ( false === strpos( $name, '\\' ) ) {
			$normalized[] = $token;
			continue;
		}

		$normalized[] = array( $name_token, $name, $line );
		$index        = $consumed;
	}

	return $normalized;
}

/**
 * Reports a condition that makes the class map unsafe to generate.
 *
 * Raised rather than reported and skipped. The map is a tracked build artifact that
 * the workflows compare with `git diff --exit-code`, so a map that has silently lost
 * a name, or that cannot represent one faithfully, has to stop generation rather than
 * be written and complained about afterwards.
 *
 * @param string $message Reason the class map cannot be generated.
 *
 * @throws RuntimeException Always.
 */
function wp_autoload_classmap_fail( $message ) {
	throw new RuntimeException( $message );
}

/**
 * Returns names a drop-in may declare instead of core.
 *
 * `wp_start_object_cache()` deliberately skips core's `cache.php` when an
 * `object-cache.php` drop-in is present, because the drop-in owns the class the
 * rest of core talks to. Mapping core's own declaration would let an early
 * `class_exists( 'WP_Object_Cache' )` load core's version behind the drop-in's
 * back, suppressing or colliding with the replacement's initialization.
 *
 * A name listed here is never mapped, so a probe for it behaves exactly as it did
 * when the bootstrap loaded core's class eagerly or not at all.
 *
 * @return string[] Names that must not be mapped.
 */
function wp_autoload_classmap_replacement_owned_names() {
	return array(
		'WP_Object_Cache',
	);
}

/**
 * Returns the prefixes the core autoloader prefilters requested names on.
 *
 * `wp_autoload_class()` returns before reading the map for any name that matches
 * none of them, so a mapped name outside the list would be unreachable at runtime.
 * The list is read out of the autoloader itself rather than repeated here, which
 * is what keeps the generated map and that prefilter from drifting apart.
 *
 * The autoloader is an authoritative input, so every way of not reading it is
 * raised rather than absorbed. An unreadable file, an empty file and a file that
 * carries no `$core_prefixes` list all used to return an empty list, and an empty
 * list is not inert: it is the value that switches the prefix filter off, so the
 * run would have gone on to publish a map built without it. Failing here is what
 * keeps "the prefixes could not be read" from being spelled the same way as
 * "there are no prefixes to apply".
 *
 * @param string $src_dir Absolute path of the `src` directory, with a trailing slash.
 * @return string[] Lower cased prefixes, never empty.
 *
 * @throws RuntimeException When the autoloader's prefix list cannot be read.
 */
function wp_autoload_classmap_core_prefixes( $src_dir ) {
	static $prefixes = null;

	if ( null !== $prefixes ) {
		return $prefixes;
	}

	$autoloader = $src_dir . 'wp-includes/autoload.php';
	$source     = @file_get_contents( $autoloader ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( false === $source || '' === $source ) {
		wp_autoload_classmap_fail(
			sprintf(
				'Unable to read %s, which declares the prefixes the autoloader prefilters requested names on. Refusing to build a class map without them.',
				$autoloader
			)
		);
	}

	$tokens = token_get_all( $source );
	$total  = count( $tokens );

	for ( $index = 0; $index < $total; $index++ ) {
		if ( ! is_array( $tokens[ $index ] )
			|| T_VARIABLE !== $tokens[ $index ][0]
			|| '$core_prefixes' !== $tokens[ $index ][1]
		) {
			continue;
		}

		$collected = array();

		for ( $next = $index + 1; $next < $total; $next++ ) {
			$token = $tokens[ $next ];

			if ( is_array( $token ) ) {
				if ( T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
					$collected[] = strtolower( trim( $token[1], '"\'' ) );
				}

				continue;
			}

			if ( ';' === $token ) {
				break;
			}
		}

		if ( array() !== $collected ) {
			$prefixes = $collected;
			break;
		}
	}

	if ( null === $prefixes || array() === $prefixes ) {
		$prefixes = null;

		wp_autoload_classmap_fail(
			sprintf(
				'No $core_prefixes list could be read from %s. Refusing to build a class map whose names cannot be checked against the autoloader\'s prefilter.',
				$autoloader
			)
		);
	}

	return $prefixes;
}

/**
 * Inspects a PHP file without loading it.
 *
 * The file is tokenized, and its top level is walked with an allow list: a
 * declaration, a `namespace` statement, a `use` import, a `declare` statement, an
 * attribute and a comment are all inert, and anything else is a side effect. An
 * allow list is used rather than a list of known side effects so that an
 * unfamiliar construct is treated as unsafe instead of being waved through.
 *
 * @param string $file Absolute path of the file to inspect.
 * @return array {
 *     What the file declares and needs.
 *
 *     @type string[] $symbols          Top level symbol names, namespace qualified.
 *     @type string[] $relatives        Names the declarations need at compile time.
 *     @type bool     $has_side_effects Whether loading the file does anything besides declaring.
 * }
 */
function wp_autoload_classmap_inspect_file( $file ) {
	$result = array(
		'symbols'          => array(),
		'relatives'        => array(),
		'has_side_effects' => false,
	);

	/*
	 * A file that cannot be read cannot be judged. Treating the failure as "declares
	 * nothing" would drop a mappable class from the map without saying so, and the
	 * loss would only appear later as a name the autoloader cannot resolve, so the
	 * read is checked and reported here instead. The suppression stays on the
	 * tokenizer alone, which warns about a source file it cannot make sense of.
	 */
	$source = file_get_contents( $file );

	if ( false === $source ) {
		wp_autoload_classmap_fail( sprintf( 'Unable to read %s while deciding whether it can be autoloaded.', $file ) );
	}

	$tokens = @token_get_all( $source );

	if ( ! is_array( $tokens ) ) {
		$result['has_side_effects'] = true;

		return $result;
	}

	// Read one tokenizer shape rather than one per supported PHP version.
	$tokens    = wp_autoload_classmap_normalize_tokens( $tokens );
	$total     = count( $tokens );
	$namespace = '';
	$imports   = array();
	$ignorable = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG );
	$modifiers = array( T_ABSTRACT, T_FINAL );
	$declaring = array( T_CLASS, T_INTERFACE, T_TRAIT );

	if ( defined( 'T_ENUM' ) ) {
		$declaring[] = T_ENUM;
	}

	if ( defined( 'T_READONLY' ) ) {
		$modifiers[] = T_READONLY;
	}

	$index = 0;

	while ( $index < $total ) {
		$token = $tokens[ $index ];

		// Punctuation at the top level only ever terminates one of the inert statements below.
		if ( is_string( $token ) ) {
			if ( ';' === $token ) {
				++$index;
				continue;
			}

			$result['has_side_effects'] = true;

			return $result;
		}

		if ( in_array( $token[0], $ignorable, true ) ) {
			++$index;
			continue;
		}

		if ( in_array( $token[0], $modifiers, true ) ) {
			++$index;
			continue;
		}

		// An attribute annotates the declaration that follows it and runs nothing.
		if ( defined( 'T_ATTRIBUTE' ) && T_ATTRIBUTE === $token[0] ) {
			$index = wp_autoload_classmap_skip_attribute( $tokens, $index );
			continue;
		}

		// `namespace Foo;` is inert. A braced namespace block is not supported here.
		if ( T_NAMESPACE === $token[0] ) {
			$namespace = '';
			++$index;

			while ( $index < $total ) {
				$inner = $tokens[ $index ];

				if ( is_string( $inner ) ) {
					if ( ';' === $inner ) {
						break;
					}

					$result['has_side_effects'] = true;

					return $result;
				}

				if ( ! in_array( $inner[0], $ignorable, true ) ) {
					$namespace .= $inner[1];
				}

				++$index;
			}

			$namespace = trim( $namespace, '\\' );
			++$index;
			continue;
		}

		// `use Foo\Bar;` at the top level imports a name and runs nothing.
		if ( T_USE === $token[0] ) {
			$statement = array();

			while ( $index < $total && ( ! is_string( $tokens[ $index ] ) || ';' !== $tokens[ $index ] ) ) {
				if ( is_string( $tokens[ $index ] ) && '{' === $tokens[ $index ] ) {
					// A grouped import closes with `}` then `;`, so tracking it is not worth it.
					$result['has_side_effects'] = true;

					return $result;
				}

				$statement[] = $tokens[ $index ];
				++$index;
			}

			$imports = array_merge( $imports, wp_autoload_classmap_read_imports( $statement ) );
			++$index;
			continue;
		}

		// `declare( strict_types = 1 );` is inert.
		if ( T_DECLARE === $token[0] ) {
			while ( $index < $total && ( ! is_string( $tokens[ $index ] ) || ';' !== $tokens[ $index ] ) ) {
				++$index;
			}

			++$index;
			continue;
		}

		if ( in_array( $token[0], $declaring, true ) ) {
			$declaration = wp_autoload_classmap_read_declaration( $tokens, $index, $namespace, $imports );

			if ( null === $declaration ) {
				$result['has_side_effects'] = true;

				return $result;
			}

			$result['symbols'][]  = $declaration['name'];
			$result['relatives']  = array_merge( $result['relatives'], $declaration['relatives'] );
			$index                = $declaration['next'];
			continue;
		}

		$result['has_side_effects'] = true;

		return $result;
	}

	$result['relatives'] = array_values( array_unique( $result['relatives'] ) );

	return $result;
}

/**
 * Reads the class aliases a top level `use` statement introduces.
 *
 * A `use function` or `use const` statement imports something other than a
 * symbol name and is ignored. Everything else contributes one alias per comma
 * separated clause, keyed by the lower cased alias so that a later lookup can be
 * case insensitive the way PHP is.
 *
 * @param array $tokens Tokens of the statement, from the `use` keyword up to but not including its `;`.
 * @return array Lower cased alias to fully qualified name.
 */
function wp_autoload_classmap_read_imports( $tokens ) {
	$naming  = wp_autoload_classmap_name_tokens();
	$imports = array();
	$name    = '';
	$alias   = '';
	$aliasing = false;

	foreach ( $tokens as $index => $token ) {
		if ( is_string( $token ) ) {
			if ( ',' === $token ) {
				if ( '' !== $name ) {
					$imports[ strtolower( wp_autoload_classmap_short_name( '' !== $alias ? $alias : $name ) ) ] = $name;
				}

				$name     = '';
				$alias    = '';
				$aliasing = false;
			}

			continue;
		}

		if ( 0 === $index && T_USE === $token[0] ) {
			continue;
		}

		if ( T_FUNCTION === $token[0] || T_CONST === $token[0] ) {
			return array();
		}

		if ( T_AS === $token[0] ) {
			$aliasing = true;
			continue;
		}

		if ( ! in_array( $token[0], $naming, true ) ) {
			continue;
		}

		if ( $aliasing ) {
			$alias = $token[1];
		} else {
			$name = ltrim( $token[1], '\\' );
		}
	}

	if ( '' !== $name ) {
		$imports[ strtolower( wp_autoload_classmap_short_name( '' !== $alias ? $alias : $name ) ) ] = $name;
	}

	return $imports;
}

/**
 * Returns the last segment of a namespace qualified name.
 *
 * @param string $name Name to shorten.
 * @return string The unqualified name.
 */
function wp_autoload_classmap_short_name( $name ) {
	$position = strrpos( $name, '\\' );

	return false === $position ? $name : substr( $name, $position + 1 );
}

/**
 * Resolves a name written in a declaration to the name PHP will look up.
 *
 * Mirrors PHP's own name resolution for the cases core uses: a leading separator
 * means the name is already absolute, an unqualified or partially qualified name
 * whose first segment matches an import is rewritten through that import, and
 * anything else is relative to the current namespace.
 *
 * @param string $name         Name as it appears in the source.
 * @param bool   $is_qualified Whether the source wrote it fully qualified, with a leading separator.
 * @param string $namespace    Namespace the declaration sits in, without a trailing separator.
 * @param array  $imports      Lower cased import alias to fully qualified name.
 * @return string The resolved name, without a leading separator.
 */
function wp_autoload_classmap_resolve_name( $name, $is_qualified, $namespace, $imports ) {
	$name = ltrim( $name, '\\' );

	if ( $is_qualified ) {
		return $name;
	}

	$segments = explode( '\\', $name );
	$first    = strtolower( $segments[0] );

	/*
	 * `namespace\Foo` names the current namespace explicitly. `namespace` is a
	 * reserved word, so it can never be an import alias or a real first segment,
	 * which is what makes stripping it here unambiguous.
	 */
	if ( 'namespace' === $first ) {
		array_shift( $segments );
		$name = implode( '\\', $segments );

		return '' !== $namespace ? $namespace . '\\' . $name : $name;
	}

	if ( isset( $imports[ $first ] ) ) {
		$segments[0] = $imports[ $first ];

		return implode( '\\', $segments );
	}

	if ( '' !== $namespace ) {
		return $namespace . '\\' . $name;
	}

	return $name;
}

/**
 * Skips an attribute, including any nested attribute inside its arguments.
 *
 * @param array $tokens Token list from token_get_all().
 * @param int   $index  Index of the T_ATTRIBUTE token.
 * @return int Index of the first token after the attribute.
 */
function wp_autoload_classmap_skip_attribute( $tokens, $index ) {
	$total = count( $tokens );
	$depth = 1;

	// The T_ATTRIBUTE token is the opening `#[` itself.
	for ( ++$index; $index < $total; $index++ ) {
		$token = $tokens[ $index ];

		if ( is_array( $token ) ) {
			if ( defined( 'T_ATTRIBUTE' ) && T_ATTRIBUTE === $token[0] ) {
				++$depth;
			}

			continue;
		}

		if ( '[' === $token ) {
			++$depth;
			continue;
		}

		if ( ']' === $token ) {
			--$depth;

			if ( 0 === $depth ) {
				return $index + 1;
			}
		}
	}

	return $total;
}

/**
 * Reads one class, interface, trait or enum declaration.
 *
 * The header between the name and the opening brace holds the parent class, the
 * implemented interfaces and, for an enum, the backing type. The body holds the
 * `use` statements that pull in traits. All of those names are needed while the
 * symbol is compiled, so all of them are collected.
 *
 * @param array  $tokens    Token list from token_get_all().
 * @param int    $index     Index of the declaring keyword.
 * @param string $namespace Namespace the declaration sits in, without a trailing separator.
 * @param array  $imports   Lower cased import alias to fully qualified name, from the file's `use` statements.
 * @return array|null {
 *     The declaration, or null when it is anonymous or malformed.
 *
 *     @type string   $name      Namespace qualified symbol name.
 *     @type string[] $relatives Names the declaration needs at compile time.
 *     @type int      $next      Index of the first token after the declaration body.
 * }
 */
function wp_autoload_classmap_read_declaration( $tokens, $index, $namespace, $imports = array() ) {
	$total     = count( $tokens );
	$ignorable = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );
	$naming    = wp_autoload_classmap_name_tokens();
	$reserved  = wp_autoload_classmap_reserved_type_names();

	// `Foo::class` reads a name, it does not declare one.
	$previous = $index - 1;

	while ( $previous >= 0 && is_array( $tokens[ $previous ] ) && in_array( $tokens[ $previous ][0], $ignorable, true ) ) {
		--$previous;
	}

	if ( $previous >= 0 && is_array( $tokens[ $previous ] ) && T_DOUBLE_COLON === $tokens[ $previous ][0] ) {
		return null;
	}

	$cursor = $index + 1;

	while ( $cursor < $total && is_array( $tokens[ $cursor ] ) && in_array( $tokens[ $cursor ][0], $ignorable, true ) ) {
		++$cursor;
	}

	// An anonymous class has no name, so it cannot be mapped.
	if ( $cursor >= $total || ! is_array( $tokens[ $cursor ] ) || T_STRING !== $tokens[ $cursor ][0] ) {
		return null;
	}

	$name      = $tokens[ $cursor ][1];
	$relatives = array();

	// Collect every name in the header, up to the opening brace.
	for ( ++$cursor; $cursor < $total; $cursor++ ) {
		if ( is_string( $tokens[ $cursor ] ) ) {
			if ( '{' === $tokens[ $cursor ] ) {
				break;
			}

			continue;
		}

		if ( in_array( $tokens[ $cursor ][0], $naming, true )
			&& ! in_array( strtolower( $tokens[ $cursor ][1] ), $reserved, true )
		) {
			$relatives[] = wp_autoload_classmap_resolve_name(
				$tokens[ $cursor ][1],
				wp_autoload_classmap_is_absolute_name( $tokens[ $cursor ][1] ),
				$namespace,
				$imports
			);
		}
	}

	if ( $cursor >= $total ) {
		return null;
	}

	$body = wp_autoload_classmap_read_body( $tokens, $cursor, $namespace, $imports );

	return array(
		'name'      => '' === $namespace ? $name : $namespace . '\\' . $name,
		'relatives' => array_merge( $relatives, $body['traits'] ),
		'next'      => $body['next'],
	);
}

/**
 * Walks a declaration body, collecting the traits it uses.
 *
 * @param array  $tokens    Token list from token_get_all().
 * @param int    $index     Index of the opening brace of the body.
 * @param string $namespace Namespace the declaration sits in, without a trailing separator.
 * @param array  $imports   Lower cased import alias to fully qualified name.
 * @return array {
 *     The body.
 *
 *     @type string[] $traits Trait names used directly in this body.
 *     @type int      $next   Index of the first token after the closing brace.
 * }
 */
function wp_autoload_classmap_read_body( $tokens, $index, $namespace = '', $imports = array() ) {
	$total  = count( $tokens );
	$naming = wp_autoload_classmap_name_tokens();
	$traits = array();
	$depth  = 0;

	for ( ; $index < $total; $index++ ) {
		$token = $tokens[ $index ];

		if ( is_string( $token ) ) {
			if ( '{' === $token ) {
				++$depth;
			} elseif ( '}' === $token ) {
				--$depth;

				if ( 0 === $depth ) {
					return array(
						'traits' => array_values( array_unique( $traits ) ),
						'next'   => $index + 1,
					);
				}
			}

			continue;
		}

		if ( T_CURLY_OPEN === $token[0] || ( defined( 'T_DOLLAR_OPEN_CURLY_BRACES' ) && T_DOLLAR_OPEN_CURLY_BRACES === $token[0] ) ) {
			++$depth;
			continue;
		}

		/*
		 * Only a `use` directly in this body imports a trait. One nested deeper
		 * belongs to an anonymous class or a closure and is that symbol's concern.
		 */
		if ( T_USE !== $token[0] || 1 !== $depth ) {
			continue;
		}

		for ( ++$index; $index < $total; $index++ ) {
			if ( is_string( $tokens[ $index ] ) ) {
				if ( ';' === $tokens[ $index ] || '{' === $tokens[ $index ] ) {
					break;
				}

				continue;
			}

			if ( in_array( $tokens[ $index ][0], $naming, true ) ) {
				$traits[] = wp_autoload_classmap_resolve_name(
					$tokens[ $index ][1],
					wp_autoload_classmap_is_absolute_name( $tokens[ $index ][1] ),
					$namespace,
					$imports
				);
			}
		}

		/*
		 * A trait adaptation block opens a brace that the outer loop has to see,
		 * so step back onto it rather than consuming it here.
		 */
		if ( $index < $total && is_string( $tokens[ $index ] ) && '{' === $tokens[ $index ] ) {
			--$index;
		}
	}

	return array(
		'traits' => array_values( array_unique( $traits ) ),
		'next'   => $total,
	);
}

/**
 * Returns the paths a file requires at its own top level.
 *
 * Only a literal path built from `ABSPATH`, `WPINC` and `__DIR__` is resolved,
 * which is the form the bootstrap uses throughout. A require nested inside a
 * function or a conditional is deliberately ignored: it does not run on every
 * request, so the names it provides cannot be treated as always available.
 *
 * @param string $file    Absolute path of the file to inspect.
 * @param string $src_dir Absolute path of the `src` directory, with a trailing slash.
 * @return string[] Paths relative to the WordPress root.
 */
function wp_autoload_classmap_required_paths( $file, $src_dir ) {
	/*
	 * Reported rather than read as an empty file, for the same reason as in
	 * wp_autoload_classmap_inspect_file(): a file whose requires cannot be read would
	 * be taken to require nothing, which would shrink the set of names the bootstrap
	 * is known to load and let a class be mapped whose parent is not resolvable.
	 */
	$source = file_get_contents( $file );

	if ( false === $source ) {
		wp_autoload_classmap_fail( sprintf( 'Unable to read %s while resolving what the bootstrap loads.', $file ) );
	}

	$tokens = @token_get_all( $source );

	if ( ! is_array( $tokens ) ) {
		return array();
	}

	// Read one tokenizer shape rather than one per supported PHP version.
	$tokens    = wp_autoload_classmap_normalize_tokens( $tokens );
	$total     = count( $tokens );
	$requiring = array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE );
	$directory = rtrim( str_replace( '\\', '/', dirname( $file ) ), '/' ) . '/';
	$root      = rtrim( str_replace( '\\', '/', $src_dir ), '/' ) . '/';
	$paths     = array();
	$depth     = 0;

	for ( $index = 0; $index < $total; $index++ ) {
		$token = $tokens[ $index ];

		if ( is_string( $token ) ) {
			if ( '{' === $token ) {
				++$depth;
			} elseif ( '}' === $token ) {
				--$depth;
			}

			continue;
		}

		if ( T_CURLY_OPEN === $token[0] || ( defined( 'T_DOLLAR_OPEN_CURLY_BRACES' ) && T_DOLLAR_OPEN_CURLY_BRACES === $token[0] ) ) {
			++$depth;
			continue;
		}

		if ( 0 !== $depth || ! in_array( $token[0], $requiring, true ) ) {
			continue;
		}

		$path     = '';
		$resolved = true;

		for ( ++$index; $index < $total; $index++ ) {
			$inner = $tokens[ $index ];

			if ( is_string( $inner ) ) {
				if ( ';' === $inner ) {
					break;
				}

				if ( '.' === $inner || '(' === $inner || ')' === $inner ) {
					continue;
				}

				$resolved = false;
				continue;
			}

			switch ( $inner[0] ) {
				case T_WHITESPACE:
				case T_COMMENT:
				case T_DOC_COMMENT:
					break;

				case T_CONSTANT_ENCAPSED_STRING:
					$path .= substr( $inner[1], 1, -1 );
					break;

				case T_DIR:
					$path .= rtrim( $directory, '/' );
					break;

				case T_STRING:
					if ( 'ABSPATH' === $inner[1] ) {
						$path .= $root;
					} elseif ( 'WPINC' === $inner[1] ) {
						$path .= 'wp-includes';
					} else {
						$resolved = false;
					}
					break;

				default:
					$resolved = false;
					break;
			}
		}

		if ( ! $resolved || '' === $path ) {
			continue;
		}

		$path = str_replace( '\\', '/', $path );

		if ( 0 !== strpos( $path, $root ) ) {
			continue;
		}

		$paths[] = substr( $path, strlen( $root ) );
	}

	return array_values( array_unique( $paths ) );
}

/**
 * Resolves the files the bootstrap loads on every request.
 *
 * Starts at `wp-settings.php` and follows top level requires transitively. The
 * result is what makes a name "always available": a symbol declared by one of
 * these files is present on every request even when it is not mapped.
 *
 * The root is normalized here rather than trusted, because every path below is
 * built by concatenating a relative path onto it: a root without a trailing slash
 * would make `wp-settings.php` unreadable, and the walk would then report an empty
 * closure instead of failing, which would in turn let every bootstrap loaded file
 * look mappable.
 *
 * Every file the walk reaches is an authoritative input for the same reason, so an
 * unreadable one is raised rather than skipped. Only unconditional, file scope
 * requires reach this walk - a guarded or nested require is never collected - so a
 * path here is one the bootstrap loads on every request, and a path the bootstrap
 * loads but this walk cannot read is a hole in the closure, not an absence. Skipping
 * it would drop the symbols it and everything it requires declare out of the set of
 * always available names, and every one of those symbols would then look mappable:
 * the map would grow entries for names the bootstrap already declares, which is the
 * one shape of entry that can end in a redeclaration fatal.
 *
 * @param string $src_dir Absolute path of the `src` directory.
 * @return string[] Paths relative to the WordPress root.
 *
 * @throws RuntimeException When a file the bootstrap loads cannot be read.
 */
function wp_autoload_classmap_bootstrap_closure( $src_dir ) {
	$src_dir = rtrim( str_replace( '\\', '/', $src_dir ), '/' ) . '/';
	$seen    = array();
	$queue   = array( 'wp-settings.php' );

	while ( $queue ) {
		$relative = array_shift( $queue );

		if ( isset( $seen[ $relative ] ) ) {
			continue;
		}

		$file = $src_dir . $relative;

		if ( ! is_readable( $file ) ) {
			wp_autoload_classmap_fail(
				sprintf(
					'Unable to read %s, which the bootstrap requires unconditionally. Refusing to build a class map from an incomplete record of what the bootstrap loads.',
					$file
				)
			);
		}

		$seen[ $relative ] = true;

		foreach ( wp_autoload_classmap_required_paths( $file, $src_dir ) as $required ) {
			$queue[] = $required;
		}
	}

	return array_keys( $seen );
}

/**
 * Returns every PHP file the class map may cover.
 *
 * @param string $src_dir Absolute path of the `src` directory, with a trailing slash.
 * @return string[] Paths relative to the WordPress root, sorted.
 */
function wp_autoload_classmap_candidate_files( $src_dir ) {
	$excluded = wp_autoload_classmap_excluded_directories();
	$files    = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $src_dir . 'wp-includes', FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $entry ) {
		if ( ! $entry->isFile() || 'php' !== strtolower( $entry->getExtension() ) ) {
			continue;
		}

		$relative = str_replace( '\\', '/', substr( $entry->getPathname(), strlen( $src_dir ) ) );
		$segments = explode( '/', $relative );

		// $segments[0] is always "wp-includes", so the first directory below it is next.
		if ( isset( $segments[1] ) && count( $segments ) > 2 && in_array( $segments[1], $excluded, true ) ) {
			continue;
		}

		$files[] = $relative;
	}

	/*
	 * Opted in by name, so an unreadable one is not simply a file this run does not
	 * cover: it is an entry the map is expected to hold and would silently lose. Both
	 * of them declare a class the bootstrap no longer requires on every request, so
	 * the loss makes that class unresolvable at runtime rather than merely absent
	 * from the map, and it would surface as an undefined class far from here.
	 */
	foreach ( wp_autoload_classmap_additional_files() as $relative ) {
		if ( ! is_readable( $src_dir . $relative ) ) {
			wp_autoload_classmap_fail(
				sprintf(
					'Unable to read %s, which is mapped by name so that the class it declares stays resolvable. Refusing to build a class map that would silently drop it.',
					$src_dir . $relative
				)
			);
		}

		$files[] = $relative;
	}

	sort( $files, SORT_STRING );

	return $files;
}

/**
 * Determines whether a name belongs to a namespace an autoloader already serves.
 *
 * @param string $name Name to test.
 * @return bool Whether the name is served by a registered autoloader.
 */
function wp_autoload_classmap_is_autoloaded_namespace( $name ) {
	foreach ( wp_autoload_classmap_autoloaded_namespaces() as $prefix ) {
		if ( 0 === strpos( $name, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Determines whether a name is one PHP itself ships.
 *
 * Deliberately not "is this name declared right now": the generator has to
 * produce the same map whatever has been loaded into the process running it, and
 * a plain declaration test would admit any WordPress class that happened to be
 * loaded. Reflection reports where a symbol comes from rather than merely that it
 * is present, so the answer is the same in a bare command line process and in a
 * fully bootstrapped one.
 *
 * @param string $name Name to test.
 * @return bool Whether PHP declares the name itself.
 */
function wp_autoload_classmap_is_internal_name( $name ) {
	// Tested without autoloading, so that asking cannot load anything.
	if ( ! class_exists( $name, false ) && ! interface_exists( $name, false ) && ! trait_exists( $name, false ) ) {
		return false;
	}

	try {
		$reflection = new ReflectionClass( $name );
	} catch ( ReflectionException $exception ) {
		return false;
	}

	return $reflection->isInternal();
}

/**
 * Builds the class map from the source tree.
 *
 * @param string $src_dir Absolute path of the `src` directory.
 * @return array {
 *     The map and the reasoning behind it.
 *
 *     @type array $map      Lower cased name to path relative to the WordPress root.
 *     @type array $rejected Lower cased name to the reason it was rejected.
 * }
 */
function wp_autoload_classmap_build( $src_dir ) {
	$src_dir = rtrim( str_replace( '\\', '/', $src_dir ), '/' ) . '/';
	$closure = array_fill_keys( wp_autoload_classmap_bootstrap_closure( $src_dir ), true );

	/*
	 * The two admin files the map reaches for are opted in by name, and every
	 * include of them in the tree is a require_once, so they stay mappable even
	 * though a conditional branch of the bootstrap names them.
	 */
	$opted_in = array_fill_keys( wp_autoload_classmap_additional_files(), true );
	$replaced = array_fill_keys( array_map( 'strtolower', wp_autoload_classmap_replacement_owned_names() ), true );
	$prefixes = wp_autoload_classmap_core_prefixes( $src_dir );

	$candidates = array();
	$always     = array();
	$rejected   = array();
	$paths      = array();

	foreach ( wp_autoload_classmap_candidate_files( $src_dir ) as $relative ) {
		$facts     = wp_autoload_classmap_inspect_file( $src_dir . $relative );
		$eligible  = 1 === count( $facts['symbols'] ) && ! $facts['has_side_effects'];
		$bootstrap = isset( $closure[ $relative ] );

		/*
		 * A file the bootstrap includes anyway has nothing to gain from being mapped,
		 * and mapping it is unsafe: an early reference would autoload it and the plain
		 * `require` that follows would then fatally redeclare its symbol. Its names are
		 * still recorded below, because they satisfy the compile time needs of files
		 * that are mapped.
		 */
		if ( $bootstrap && ! isset( $opted_in[ $relative ] ) ) {
			$eligible = false;
		}

		/*
		 * A file whose first top level statement already runs code has no trusted
		 * symbol list, so the audit trail has to name the file instead of a symbol.
		 */
		if ( ! $facts['symbols'] ) {
			$rejected[ $relative ] = $facts['has_side_effects'] ? 'file scope side effects' : 'declares no symbol';
			continue;
		}

		foreach ( $facts['symbols'] as $symbol ) {
			$key = strtolower( $symbol );

			/*
			 * A symbol the bootstrap always loads is available whether it is mapped
			 * or not, so it can satisfy another symbol's compile time needs.
			 */
			if ( $bootstrap ) {
				$always[ $key ] = true;
			}

			if ( ! $eligible ) {
				if ( $bootstrap && ! isset( $opted_in[ $relative ] ) ) {
					$rejected[ $key ] = 'always loaded by the bootstrap';
				} else {
					$rejected[ $key ] = $facts['has_side_effects'] ? 'file scope side effects' : 'file declares ' . count( $facts['symbols'] ) . ' symbols';
				}

				continue;
			}

			/*
			 * The map is keyed on the bare name the autoloader is handed, so a name
			 * declared inside a namespace has no key it could be stored under. Rejecting
			 * it here rather than letting the renderer refuse it is what keeps such a
			 * file out of the map without stopping generation: the prefix test below
			 * compares against the qualified name, so a namespace that happens to begin
			 * with a core prefix would otherwise reach the renderer and turn an
			 * ineligible file into a failed build.
			 */
			if ( false !== strpos( $key, '\\' ) ) {
				$rejected[ $key ] = 'declared inside a namespace';
				continue;
			}

			// A drop-in owns the declaration, so core's own must stay unmapped.
			if ( isset( $replaced[ $key ] ) ) {
				$rejected[ $key ] = 'owned by a drop-in replacement';
				continue;
			}

			/*
			 * The autoloader never reads the map for a name outside these prefixes, so
			 * mapping one would produce an entry that can never be reached.
			 */
			if ( $prefixes ) {
				$prefixed = false;

				foreach ( $prefixes as $prefix ) {
					if ( 0 === strncmp( $key, $prefix, strlen( $prefix ) ) ) {
						$prefixed = true;
						break;
					}
				}

				if ( ! $prefixed ) {
					$rejected[ $key ] = 'outside the autoloader prefix list';
					continue;
				}
			}

			/*
			 * A name may be claimed once. Keys are lower cased because PHP resolves a
			 * class, interface or trait name case insensitively, so two declarations
			 * whose names differ only by case collapse onto the same key: keeping
			 * whichever was inspected last would leave the other silently unmapped and
			 * therefore unloadable, and loading both would raise "Cannot redeclare" in
			 * any case. Either kind of clash means the source tree is what has to
			 * change, so generation stops and names both files.
			 */
			if ( isset( $paths[ $key ] ) ) {
				wp_autoload_classmap_fail(
					sprintf(
						'Duplicate class map name %1$s, declared in both %2$s and %3$s. A name may be declared by only one mapped file.',
						$symbol,
						$paths[ $key ],
						$relative
					)
				);
			}

			$candidates[ $key ] = $facts['relatives'];
			$paths[ $key ]      = $relative;
		}
	}

	/*
	 * Dropping a symbol invalidates everything that inherits from it, so keep
	 * pruning until a pass changes nothing.
	 */
	do {
		$pruned = false;

		foreach ( $candidates as $key => $relatives ) {
			foreach ( $relatives as $relative_name ) {
				$relative_key = strtolower( $relative_name );

				if ( isset( $candidates[ $relative_key ] ) || isset( $always[ $relative_key ] ) ) {
					continue;
				}

				if ( wp_autoload_classmap_is_autoloaded_namespace( $relative_name ) ) {
					continue;
				}

				// A name PHP ships is available to every file without being loaded.
				if ( wp_autoload_classmap_is_internal_name( $relative_name ) ) {
					continue;
				}

				$rejected[ $key ] = 'unresolvable ' . $relative_name;
				unset( $candidates[ $key ] );
				$pruned = true;
				break;
			}
		}
	} while ( $pruned );

	$map = array();

	foreach ( array_keys( $candidates ) as $key ) {
		$map[ $key ] = $paths[ $key ];
	}

	ksort( $map, SORT_STRING );
	ksort( $rejected, SORT_STRING );

	return array(
		'map'      => $map,
		'rejected' => $rejected,
	);
}

/**
 * Determines whether a path may be emitted as a class map value.
 *
 * `wp_autoload_class()` resolves a value as `ABSPATH . $path` and loads the
 * result, so a value is only admissible when it can name nothing but a PHP file
 * inside the two trees the map covers. The form required here is therefore
 * canonical rather than merely plausible:
 *
 * - It is rooted at `wp-includes/` or at `wp-admin/includes/`. The wider
 *   `wp-admin/` is not accepted, because the map only ever reaches the two admin
 *   includes that wp_autoload_classmap_additional_files() opts in.
 * - Every segment is non-empty and starts with a character other than a dot, which
 *   is what makes `..`, `.` and an empty segment from a doubled separator all
 *   unrepresentable, whatever produced them.
 * - It ends in `.php`, and it carries no backslash, no leading separator and no
 *   character outside the identifier set core file names are built from.
 *
 * The same form is enforced independently by the autoloader in
 * `wp-includes/autoload.php`, which treats anything else as a miss: the generator
 * refuses to write such a value, and the runtime refuses to act on one, so neither
 * relies on the other having got it right.
 *
 * @param mixed $path Candidate value, as it would be emitted.
 * @return bool Whether the value is a canonical core path the autoloader may load.
 */
function wp_autoload_classmap_is_loadable_path( $path ) {
	return is_string( $path )
		&& 1 === preg_match(
			'#^(?:wp-includes|wp-admin/includes)/(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*\.php$#',
			$path
		);
}

/**
 * Renders the generated class map file.
 *
 * @param array $map Lower cased name to path relative to the WordPress root.
 * @return string File contents, ready to be written.
 */
function wp_autoload_classmap_render( $map ) {
	$lines = array(
		'<?php',
		'',
		'// This file was autogenerated by Gruntfile.js, do not change manually!',
		'// Returns the map of core class, interface and trait names to their files, for the core autoloader.',
		'// Names are lower cased, because PHP resolves them case insensitively and wp_autoload_class() lower cases its argument.',
		'// phpcs:disable WordPress.Arrays.MultipleStatementAlignment -- Generated by the build:autoload-classmap task in Gruntfile.js; padding the double arrows would re-pad every entry whenever a symbol name of a different length is added.',
		'return array(',
	);

	/*
	 * An entry less map would switch the autoloader off while still looking like a
	 * legitimate build result, and copy:files would then ship it.
	 */
	if ( ! $map ) {
		wp_autoload_classmap_fail( 'No core names were found; refusing to render an empty autoload class map.' );
	}

	foreach ( $map as $name => $path ) {
		/*
		 * Both halves of an entry are emitted as single quoted PHP, so neither may
		 * carry a quote or a backslash. The eligibility rules already make anything
		 * else impossible, so this is a second and independent guard that keeps the
		 * generator from writing PHP source if those rules, or the shape of the tree,
		 * ever change.
		 */
		if ( ! preg_match( '/^[a-z_\x80-\xff][a-z0-9_\x80-\xff]*$/', $name ) ) {
			wp_autoload_classmap_fail(
				sprintf(
					'Refusing to emit the class map name %s: not a lower cased PHP identifier.',
					var_export( $name, true )
				)
			);
		}

		if ( ! wp_autoload_classmap_is_loadable_path( $path ) ) {
			wp_autoload_classmap_fail(
				sprintf(
					'Refusing to emit the class map path %1$s for %2$s: every mapped path must be a forward slashed .php path whose segments are all non-empty and dot free, below wp-includes/ or wp-admin/includes/.',
					var_export( $path, true ),
					$name
				)
			);
		}

		$lines[] = sprintf( "\t'%s' => '%s',", $name, $path );
	}

	$lines[] = ');';
	$lines[] = '';

	return implode( "\n", $lines );
}

/**
 * Creates a temporary file beside the target and returns it, or fails.
 *
 * The name is unpredictable and the file is created exclusively, which is what
 * makes the handle the only reference to it. A name derived from the process ID is
 * guessable, and `getmypid()` is reused by the operating system, so another process
 * able to write the directory could have placed a file - or a symbolic link to one
 * it does not own - at that path first. `file_put_contents()` would then have
 * followed the link and written the class map through it, and the `chmod()` below
 * would have relaxed the mode of whatever it pointed at.
 *
 * `x` is the guard: the open fails outright when the path already exists, and it
 * does not follow a symbolic link to create the target it names. The mode is
 * passed as 0600 so that the file is never briefly group or world readable while it
 * is being written, whatever the umask says.
 *
 * @param string $file Absolute path of the file that will be replaced.
 * @return array {
 *     The created temporary file.
 *
 *     @type string   $path   Absolute path of the temporary file.
 *     @type resource $handle Open write handle for it.
 * }
 *
 * @throws RuntimeException When no temporary file could be created.
 */
function wp_autoload_classmap_open_temporary_file( $file ) {
	/*
	 * Attempts are bounded rather than unbounded: with 16 random bytes a collision
	 * is not the reason an exclusive create fails twice in a row, so a directory that
	 * keeps refusing the create is reported instead of being retried forever.
	 */
	$attempts = 8;

	for ( $attempt = 0; $attempt < $attempts; $attempt++ ) {
		// Beside the target, so that the rename in the caller stays within one filesystem.
		$path   = $file . '.tmp' . bin2hex( random_bytes( 16 ) );
		$handle = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $handle ) {
			@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			return array(
				'path'   => $path,
				'handle' => $handle,
			);
		}
	}

	wp_autoload_classmap_fail(
		sprintf(
			'Cannot write the autoload class map: no temporary file could be created beside %1$s in %2$d attempts.',
			$file,
			$attempts
		)
	);
}

/**
 * Fails after removing a temporary file, whatever went wrong with it.
 *
 * Collected here so that no failing path can return without closing the handle and
 * unlinking the file it created. The handle is closed first, because the file is
 * removed by the path it was created under and a still open handle would otherwise
 * keep the bytes alive for as long as this process runs.
 *
 * @param resource $handle    Open handle for the temporary file.
 * @param string   $temporary Absolute path of the temporary file.
 * @param string   $message   Diagnostic to report.
 * @return void
 *
 * @throws RuntimeException Always.
 */
function wp_autoload_classmap_discard_temporary_file( $handle, $temporary, $message ) {
	if ( is_resource( $handle ) ) {
		fclose( $handle );
	}

	@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	wp_autoload_classmap_fail( $message );
}

/**
 * Publishes new contents for a file, or fails.
 *
 * The class map is a tracked artifact that the build copies into `build/` and that
 * 15 workflows compare with `git diff --exit-code`, so a partially written map is
 * worse than no map at all: it looks like a legitimate result while resolving only
 * the names that made it to disk. The write is therefore never trusted for having
 * been attempted.
 *
 * New contents go to an exclusively created temporary file beside the target, so
 * that the target is only ever replaced by a `rename()`, which is atomic within one
 * filesystem: a reader sees either the whole previous file or the whole new one,
 * never a prefix of the new one. Every step is checked - the identity of the file
 * the handle actually holds, the byte count the write reports, the bytes that can be
 * read back, and the rename itself - and the temporary file is removed on every
 * failing path so a failed generation leaves nothing behind in the source tree.
 *
 * The identity check is what makes the write safe rather than merely atomic. The
 * handle is the reference every mutation goes through, and `fstat()` describes the
 * file that handle holds rather than whatever the path resolves to by the time the
 * mutation runs, so comparing it with the `lstat()` of the path proves that the two
 * are still the same file, that it is a regular file, and that nothing else links to
 * it. A path swapped between the create and the write therefore fails the build
 * instead of redirecting it.
 *
 * @param string $file     Absolute path of the file to replace.
 * @param string $contents Contents to publish.
 * @return void
 *
 * @throws RuntimeException When the contents cannot be published in full.
 */
function wp_autoload_classmap_replace_file( $file, $contents ) {
	$directory = dirname( $file );

	if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
		wp_autoload_classmap_fail(
			sprintf( 'Cannot write the autoload class map: %s is not a writable directory.', $directory )
		);
	}

	$temporary_file = wp_autoload_classmap_open_temporary_file( $file );
	$temporary      = $temporary_file['path'];
	$handle         = $temporary_file['handle'];

	$handle_stat = fstat( $handle );
	clearstatcache( true, $temporary );
	$path_stat = @lstat( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( ! is_array( $handle_stat ) || ! is_array( $path_stat ) ) {
		wp_autoload_classmap_discard_temporary_file(
			$handle,
			$temporary,
			sprintf( 'Cannot write the autoload class map: %s could not be identified after it was created.', $temporary )
		);
	}

	/*
	 * 0100000 is S_IFREG. A link count above one means a second name reaches these
	 * bytes, and a device or inode that no longer matches means the path names a
	 * different file than the handle holds.
	 */
	if ( 0100000 !== ( $handle_stat['mode'] & 0170000 )
		|| 1 !== (int) $handle_stat['nlink']
		|| $handle_stat['dev'] !== $path_stat['dev']
		|| $handle_stat['ino'] !== $path_stat['ino']
	) {
		wp_autoload_classmap_discard_temporary_file(
			$handle,
			$temporary,
			sprintf(
				'Cannot write the autoload class map: %s is not the exclusively created regular file it was opened as.',
				$temporary
			)
		);
	}

	$written = fwrite( $handle, $contents );

	if ( strlen( $contents ) !== $written || ! fflush( $handle ) ) {
		wp_autoload_classmap_discard_temporary_file(
			$handle,
			$temporary,
			sprintf(
				'Cannot write the autoload class map: %1$s took %2$s of %3$d bytes.',
				$temporary,
				false === $written ? 'none' : $written,
				strlen( $contents )
			)
		);
	}

	/*
	 * A new file takes its mode from the umask, so the mode the published file
	 * already carries is restored rather than replaced by whatever this process
	 * happens to run under. It is applied to the open handle, so it cannot reach a
	 * file that replaced the path since the identity check above.
	 */
	$permissions = @fileperms( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( false !== $permissions && ! @chmod( $temporary, $permissions & 0777 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		wp_autoload_classmap_discard_temporary_file(
			$handle,
			$temporary,
			sprintf( 'Cannot write the autoload class map: the mode of %s could not be set.', $temporary )
		);
	}

	fclose( $handle );
	clearstatcache( true, $temporary );

	if ( @file_get_contents( $temporary ) !== $contents ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		wp_autoload_classmap_fail(
			sprintf( 'Cannot write the autoload class map: %s did not read back as it was written.', $temporary )
		);
	}

	if ( ! @rename( $temporary, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		wp_autoload_classmap_fail(
			sprintf( 'Cannot publish the autoload class map: %1$s could not replace %2$s.', $temporary, $file )
		);
	}
}

/**
 * Writes the generated class map to disk.
 *
 * @param string $src_dir Absolute path of the `src` directory.
 * @return array {
 *     What was written.
 *
 *     @type string $file    Absolute path of the file that was written.
 *     @type int    $entries Number of mapped names.
 *     @type bool   $changed Whether this run rewrote the file. False means the file
 *                           already held this run's output, never that a write was
 *                           attempted and did not land: that condition is raised.
 *     @type int    $bytes   Length of the published contents.
 *     @type string $sha256  Digest of the published contents.
 * }
 *
 * @throws RuntimeException When the map on disk is not the map that was rendered.
 */
function wp_autoload_classmap_write( $src_dir ) {
	$src_dir  = rtrim( str_replace( '\\', '/', $src_dir ), '/' ) . '/';
	$built    = wp_autoload_classmap_build( $src_dir );
	$contents = wp_autoload_classmap_render( $built['map'] );
	$file     = $src_dir . 'wp-includes/autoload-classmap.php';
	$existing = is_readable( $file ) ? file_get_contents( $file ) : null;
	$changed  = false;

	if ( $contents !== $existing ) {
		wp_autoload_classmap_replace_file( $file, $contents );

		/*
		 * Recorded once the replacement has landed rather than from the comparison
		 * above, so that reporting a rewrite means one happened. A write that does not
		 * land leaves the previous map in place, and that map is well formed: it is
		 * simply missing whatever the source tree has gained since it was produced.
		 * copy:files would package it as this run's output, and the autoloader answers
		 * a name the map has lost by doing nothing, so the loss would surface much
		 * later as an unresolvable class rather than as a failed build.
		 */
		$changed = true;
	}

	/*
	 * Read back rather than assumed, on the unchanged path as well as the rewritten
	 * one: what the build copies and what the workflows compare is the file on disk,
	 * so that is what has to be proved to hold the rendered map. This is also what
	 * catches a map left stale by an earlier interrupted run.
	 */
	$published = is_readable( $file ) ? file_get_contents( $file ) : false;

	if ( $published !== $contents ) {
		wp_autoload_classmap_fail(
			sprintf( 'The autoload class map at %s does not hold the map that was generated.', $file )
		);
	}

	return array(
		'file'    => $file,
		'entries' => count( $built['map'] ),
		'changed' => $changed,
		'bytes'   => strlen( $contents ),
		'sha256'  => hash( 'sha256', $contents ),
	);
}

// Running this file directly regenerates the map. Requiring it only defines the functions above.
if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	try {
		$written = wp_autoload_classmap_write( isset( $argv[1] ) ? $argv[1] : dirname( __DIR__, 2 ) . '/src' );
	} catch ( RuntimeException $exception ) {
		/*
		 * Reported on standard error and with a nonzero status, so that
		 * build:autoload-classmap fails the build instead of shipping whatever happens
		 * to be on disk. Every condition raised above already names the file, the symbol
		 * or the path that stopped generation, so the message is the whole diagnosis,
		 * and it is reported on one line rather than as an uncaught exception because a
		 * trace through this generator's own call chain only buries the line that
		 * matters in the build log.
		 *
		 * Only conditions this file raises on purpose are caught:
		 * wp_autoload_classmap_fail() throws RuntimeException, and the one other
		 * deliberate failure, a missing input directory, reaches here as the
		 * UnexpectedValueException that extends it. The unwinding of a genuine defect is
		 * deliberately left alone, so it keeps its trace.
		 */
		fwrite( STDERR, 'Autoload class map generation failed: ' . $exception->getMessage() . PHP_EOL );
		exit( 1 );
	}

	printf(
		"%s %d entries in %s%s",
		$written['changed'] ? 'Wrote' : 'Verified',
		$written['entries'],
		$written['file'],
		PHP_EOL
	);

	/*
	 * The same facts again in one machine readable line, which
	 * build:autoload-classmap checks the published file against so that the task
	 * cannot report success for a map that the file on disk does not hold.
	 */
	printf(
		'AUTOLOAD_CLASSMAP_DIGEST entries=%d bytes=%d sha256=%s%s',
		$written['entries'],
		$written['bytes'],
		$written['sha256'],
		PHP_EOL
	);
}
