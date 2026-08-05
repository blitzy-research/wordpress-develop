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
 * Returns the namespace prefixes served by autoloaders that the bootstrap registers.
 *
 * A name under one of these prefixes resolves on any request without core doing
 * anything, so a core class may extend or implement it and still be safe to
 * autoload. Every prefix here has to be backed by a registration that happens
 * during the bootstrap on every request:
 *
 * - `WordPress\AiClient\` and `WordPress\AiClientDependencies\` are registered by
 *   `wp-includes/php-ai-client/autoload.php`, which `wp-settings.php` requires.
 * - `WpOrg\Requests\` is registered by `wp-includes/class-wp-http.php`, which
 *   `wp-settings.php` requires, through `WpOrg\Requests\Autoload::register()`.
 *
 * A library whose autoloader is registered lazily at the point of use, such as
 * SimplePie, deliberately does not belong here.
 *
 * @return string[] Namespace prefixes, each ending in a backslash.
 */
function wp_autoload_classmap_autoloaded_namespaces() {
	return array(
		'WordPress\\AiClient\\',
		'WordPress\\AiClientDependencies\\',
		'WpOrg\\Requests\\',
	);
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
 * @param string $src_dir Absolute path of the `src` directory, with a trailing slash.
 * @return string[] Lower cased prefixes. Empty when the declaration cannot be read.
 */
function wp_autoload_classmap_core_prefixes( $src_dir ) {
	static $prefixes = null;

	if ( null !== $prefixes ) {
		return $prefixes;
	}

	$prefixes = array();
	$source   = @file_get_contents( $src_dir . 'wp-includes/autoload.php' );

	if ( false === $source ) {
		return $prefixes;
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

	$tokens = @token_get_all( (string) file_get_contents( $file ) );

	if ( ! is_array( $tokens ) ) {
		$result['has_side_effects'] = true;

		return $result;
	}

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
	$naming  = array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED );
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
	$naming    = array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED );
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
				T_NAME_FULLY_QUALIFIED === $tokens[ $cursor ][0],
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
	$naming = array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED );
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
					T_NAME_FULLY_QUALIFIED === $tokens[ $index ][0],
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
	$tokens = @token_get_all( (string) file_get_contents( $file ) );

	if ( ! is_array( $tokens ) ) {
		return array();
	}

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
 * @param string $src_dir Absolute path of the `src` directory, with a trailing slash.
 * @return string[] Paths relative to the WordPress root.
 */
function wp_autoload_classmap_bootstrap_closure( $src_dir ) {
	$seen  = array();
	$queue = array( 'wp-settings.php' );

	while ( $queue ) {
		$relative = array_shift( $queue );

		if ( isset( $seen[ $relative ] ) ) {
			continue;
		}

		$file = $src_dir . $relative;

		if ( ! is_readable( $file ) ) {
			continue;
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

	foreach ( wp_autoload_classmap_additional_files() as $relative ) {
		if ( is_readable( $src_dir . $relative ) ) {
			$files[] = $relative;
		}
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
		'// phpcs:disable WordPress.Arrays.MultipleStatementAlignment -- Generated by the build:autoload-classmap task in Gruntfile.js; padding the double arrows would re-pad every entry whenever a class name of a different length is added.',
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

		if ( ! preg_match( '#^(?:wp-includes|wp-admin)/[A-Za-z0-9_./-]+\.php$#', $path ) ) {
			wp_autoload_classmap_fail(
				sprintf(
					'Refusing to emit the class map path %1$s for %2$s: every mapped path must be a forward slashed path below wp-includes/ or wp-admin/.',
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
 * Writes the generated class map to disk.
 *
 * @param string $src_dir Absolute path of the `src` directory.
 * @return array {
 *     What was written.
 *
 *     @type string $file    Absolute path of the file that was written.
 *     @type int    $entries Number of mapped names.
 *     @type bool   $changed Whether the file contents changed.
 * }
 */
function wp_autoload_classmap_write( $src_dir ) {
	$src_dir  = rtrim( str_replace( '\\', '/', $src_dir ), '/' ) . '/';
	$built    = wp_autoload_classmap_build( $src_dir );
	$contents = wp_autoload_classmap_render( $built['map'] );
	$file     = $src_dir . 'wp-includes/autoload-classmap.php';
	$existing = is_readable( $file ) ? file_get_contents( $file ) : null;

	if ( $contents !== $existing ) {
		file_put_contents( $file, $contents );
	}

	return array(
		'file'    => $file,
		'entries' => count( $built['map'] ),
		'changed' => $contents !== $existing,
	);
}

// Running this file directly regenerates the map. Requiring it only defines the functions above.
if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	$written = wp_autoload_classmap_write( isset( $argv[1] ) ? $argv[1] : dirname( __DIR__, 2 ) . '/src' );

	printf(
		"%s %d entries in %s%s",
		$written['changed'] ? 'Wrote' : 'Verified',
		$written['entries'],
		$written['file'],
		PHP_EOL
	);
}
