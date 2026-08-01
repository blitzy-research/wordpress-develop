<?php
/**
 * Core User Role & Capabilities API
 *
 * @package WordPress
 * @subpackage Users
 */

/**
 * Maps a capability to the primitive capabilities required of the given user to
 * satisfy the capability being checked.
 *
 * This function also accepts an ID of an object to map against if the capability is a meta capability. Meta
 * capabilities such as `edit_post` and `edit_user` are capabilities used by this function to map to primitive
 * capabilities that a user or role requires, such as `edit_posts` and `edit_others_posts`.
 *
 * Example usage:
 *
 *     map_meta_cap( 'edit_posts', $user->ID );
 *     map_meta_cap( 'edit_post', $user->ID, $post->ID );
 *     map_meta_cap( 'edit_post_meta', $user->ID, $post->ID, $meta_key );
 *
 * This function does not check whether the user has the required capabilities,
 * it just returns what the required capabilities are.
 *
 * @since 2.0.0
 * @since 4.9.6 Added the `export_others_personal_data`, `erase_others_personal_data`,
 *              and `manage_privacy_options` capabilities.
 * @since 5.1.0 Added the `update_php` capability.
 * @since 5.2.0 Added the `resume_plugin` and `resume_theme` capabilities.
 * @since 5.3.0 Formalized the existing and already documented `...$args` parameter
 *              by adding it to the function signature.
 * @since 5.7.0 Added the `create_app_password`, `list_app_passwords`, `read_app_password`,
 *              `edit_app_password`, `delete_app_passwords`, `delete_app_password`,
 *              and `update_https` capabilities.
 * @since 6.7.0 Added the `edit_block_binding` capability.
 * @since 7.0.0 Eligible unfiltered mappings may be memoized for the duration of the request.
 *
 * @global array $post_type_meta_caps Used to get post type meta capabilities.
 *
 * @param string $cap     Capability being checked.
 * @param int    $user_id User ID.
 * @param mixed  ...$args Optional further parameters, typically starting with an object ID.
 * @return string[] Primitive capabilities required of the user.
 */
function map_meta_cap( $cap, $user_id, ...$args ) {
	/*
	 * Answer from the request-scoped memo when this exact question has already been
	 * asked and answered during this request. The key is derived from the capability
	 * as it was passed in, because the switch below rewrites $cap on two paths, and it
	 * is an empty string whenever the answer could legitimately differ between two
	 * otherwise identical calls.
	 *
	 * Calls that pass no further arguments are the majority of all capability checks
	 * and are answered by the cheap arms of the switch, so they skip the memo without
	 * even the cost of a call to the function that would decline them.
	 */
	$memo_key = empty( $args ) ? '' : _wp_map_meta_cap_memo_key( $cap, $user_id, $args );

	if ( '' !== $memo_key ) {
		$memoized_caps = _wp_map_meta_cap_memo( $memo_key );

		if ( null !== $memoized_caps ) {
			return $memoized_caps;
		}
	}

	$caps = array();

	switch ( $cap ) {
		case 'remove_user':
			// In multisite the user must be a super admin to remove themselves.
			if ( isset( $args[0] ) && $user_id === (int) $args[0] && ! is_super_admin( $user_id ) ) {
				$caps[] = 'do_not_allow';
			} else {
				$caps[] = 'remove_users';
			}
			break;
		case 'promote_user':
		case 'add_users':
			$caps[] = 'promote_users';
			break;
		case 'edit_user':
		case 'edit_users':
			// Non-existent users can't edit users, not even themselves.
			if ( $user_id < 1 ) {
				$caps[] = 'do_not_allow';
				break;
			}

			// Allow user to edit themselves.
			if ( 'edit_user' === $cap && isset( $args[0] ) && $user_id === (int) $args[0] ) {
				break;
			}

			// In multisite the user must have manage_network_users caps. If editing a super admin, the user must be a super admin.
			if ( is_multisite() && ( ( ! is_super_admin( $user_id ) && 'edit_user' === $cap && is_super_admin( $args[0] ) ) || ! user_can( $user_id, 'manage_network_users' ) ) ) {
				$caps[] = 'do_not_allow';
			} else {
				$caps[] = 'edit_users'; // edit_user maps to edit_users.
			}
			break;
		case 'delete_post':
		case 'delete_page':
			if ( ! isset( $args[0] ) ) {
				if ( 'delete_post' === $cap ) {
					/* translators: %s: Capability name. */
					$message = __( 'When checking for the %s capability, you must always check it against a specific post.' );
				} else {
					/* translators: %s: Capability name. */
					$message = __( 'When checking for the %s capability, you must always check it against a specific page.' );
				}

				_doing_it_wrong(
					__FUNCTION__,
					sprintf( $message, '<code>' . $cap . '</code>' ),
					'6.1.0'
				);

				$caps[] = 'do_not_allow';
				break;
			}

			$post = get_post( $args[0] );
			if ( ! $post ) {
				$caps[] = 'do_not_allow';
				break;
			}

			if ( 'revision' === $post->post_type ) {
				$caps[] = 'do_not_allow';
				break;
			}

			if ( (int) get_option( 'page_for_posts' ) === $post->ID
				|| (int) get_option( 'page_on_front' ) === $post->ID
			) {
				$caps[] = 'manage_options';
				break;
			}

			$post_type = get_post_type_object( $post->post_type );
			if ( ! $post_type ) {
				/* translators: 1: Post type, 2: Capability name. */
				$message = __( 'The post type %1$s is not registered, so it may not be reliable to check the capability %2$s against a post of that type.' );

				_doing_it_wrong(
					__FUNCTION__,
					sprintf(
						$message,
						'<code>' . $post->post_type . '</code>',
						'<code>' . $cap . '</code>'
					),
					'4.4.0'
				);

				$caps[] = 'edit_others_posts';
				break;
			}

			if ( ! $post_type->map_meta_cap ) {
				$caps[] = $post_type->cap->$cap;
				// Prior to 3.1 we would re-call map_meta_cap here.
				if ( 'delete_post' === $cap ) {
					$cap = $post_type->cap->$cap;
				}
				break;
			}

			// If the post author is set and the user is the author...
			if ( $post->post_author && $user_id === (int) $post->post_author ) {
				// If the post is published or scheduled...
				if ( in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
					$caps[] = $post_type->cap->delete_published_posts;
				} elseif ( 'trash' === $post->post_status ) {
					$status = get_post_meta( $post->ID, '_wp_trash_meta_status', true );
					if ( in_array( $status, array( 'publish', 'future' ), true ) ) {
						$caps[] = $post_type->cap->delete_published_posts;
					} else {
						$caps[] = $post_type->cap->delete_posts;
					}
				} else {
					// If the post is draft...
					$caps[] = $post_type->cap->delete_posts;
				}
			} else {
				// The user is trying to edit someone else's post.
				$caps[] = $post_type->cap->delete_others_posts;
				// The post is published or scheduled, extra cap required.
				if ( in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
					$caps[] = $post_type->cap->delete_published_posts;
				} elseif ( 'private' === $post->post_status ) {
					$caps[] = $post_type->cap->delete_private_posts;
				}
			}

			/*
			 * Setting the privacy policy page requires `manage_privacy_options`,
			 * so deleting it should require that too.
			 */
			if ( (int) get_option( 'wp_page_for_privacy_policy' ) === $post->ID ) {
				$caps = array_merge( $caps, map_meta_cap( 'manage_privacy_options', $user_id ) );
			}

			break;
		/*
		 * edit_post breaks down to edit_posts, edit_published_posts, or
		 * edit_others_posts.
		 */
		case 'edit_post':
		case 'edit_page':
			if ( ! isset( $args[0] ) ) {
				if ( 'edit_post' === $cap ) {
					/* translators: %s: Capability name. */
					$message = __( 'When checking for the %s capability, you must always check it against a specific post.' );
				} else {
					/* translators: %s: Capability name. */
					$message = __( 'When checking for the %s capability, you must always check it against a specific page.' );
				}

				_doing_it_wrong(
					__FUNCTION__,
					sprintf( $message, '<code>' . $cap . '</code>' ),
					'6.1.0'
				);

				$caps[] = 'do_not_allow';
				break;
			}

			$post = get_post( $args[0] );
			if ( ! $post ) {
				$caps[] = 'do_not_allow';
				break;
			}

			if ( 'revision' === $post->post_type ) {
				$post = get_post( $post->post_parent );
				if ( ! $post ) {
					$caps[] = 'do_not_allow';
					break;
				}
			}

			$post_type = get_post_type_object( $post->post_type );
			if ( ! $post_type ) {
				/* translators: 1: Post type, 2: Capability name. */
				$message = __( 'The post type %1$s is not registered, so it may not be reliable to check the capability %2$s against a post of that type.' );

				_doing_it_wrong(
					__FUNCTION__,
					sprintf(
						$message,
						'<code>' . $post->post_type . '</code>',
						'<code>' . $cap . '</code>'
					),
					'4.4.0'
				);

				$caps[] = 'edit_others_posts';
				break;
			}

			if ( ! $post_type->map_meta_cap ) {
				$caps[] = $post_type->cap->$cap;
				// Prior to 3.1 we would re-call map_meta_cap here.
				if ( 'edit_post' === $cap ) {
					$cap = $post_type->cap->$cap;
				}
				break;
			}

			// If the post author is set and the user is the author...
			if ( $post->post_author && $user_id === (int) $post->post_author ) {
				// If the post is published or scheduled...
				if ( in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
					$caps[] = $post_type->cap->edit_published_posts;
				} elseif ( 'trash' === $post->post_status ) {
					$status = get_post_meta( $post->ID, '_wp_trash_meta_status', true );
					if ( in_array( $status, array( 'publish', 'future' ), true ) ) {
						$caps[] = $post_type->cap->edit_published_posts;
					} else {
						$caps[] = $post_type->cap->edit_posts;
					}
				} else {
					// If the post is draft...
					$caps[] = $post_type->cap->edit_posts;
				}
			} else {
				// The user is trying to edit someone else's post.
				$caps[] = $post_type->cap->edit_others_posts;
				// The post is published or scheduled, extra cap required.
				if ( in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
					$caps[] = $post_type->cap->edit_published_posts;
				} elseif ( 'private' === $post->post_status ) {
					$caps[] = $post_type->cap->edit_private_posts;
				}
			}

			/*
			 * Setting the privacy policy page requires `manage_privacy_options`,
			 * so editing it should require that too.
			 */
			if ( (int) get_option( 'wp_page_for_privacy_policy' ) === $post->ID ) {
				$caps = array_merge( $caps, map_meta_cap( 'manage_privacy_options', $user_id ) );
			}

			break;
		case 'read_post':
		case 'read_page':
			if ( ! isset( $args[0] ) ) {
				if ( 'read_post' === $cap ) {
					/* translators: %s: Capability name. */
					$message = __( 'When checking for the %s capability, you must always check it against a specific post.' );
				} else {
					/* translators: %s: Capability name. */
					$message = __( 'When checking for the %s capability, you must always check it against a specific page.' );
				}

				_doing_it_wrong(
					__FUNCTION__,
					sprintf( $message, '<code>' . $cap . '</code>' ),
					'6.1.0'
				);

				$caps[] = 'do_not_allow';
				break;
			}

			$post = get_post( $args[0] );
			if ( ! $post ) {
				$caps[] = 'do_not_allow';
				break;
			}

			if ( 'revision' === $post->post_type ) {
				$post = get_post( $post->post_parent );
				if ( ! $post ) {
					$caps[] = 'do_not_allow';
					break;
				}
			}

			$post_type = get_post_type_object( $post->post_type );
			if ( ! $post_type ) {
				/* translators: 1: Post type, 2: Capability name. */
				$message = __( 'The post type %1$s is not registered, so it may not be reliable to check the capability %2$s against a post of that type.' );

				_doing_it_wrong(
					__FUNCTION__,
					sprintf(
						$message,
						'<code>' . $post->post_type . '</code>',
						'<code>' . $cap . '</code>'
					),
					'4.4.0'
				);

				$caps[] = 'edit_others_posts';
				break;
			}

			if ( ! $post_type->map_meta_cap ) {
				$caps[] = $post_type->cap->$cap;
				// Prior to 3.1 we would re-call map_meta_cap here.
				if ( 'read_post' === $cap ) {
					$cap = $post_type->cap->$cap;
				}
				break;
			}

			$status_obj = get_post_status_object( get_post_status( $post ) );
			if ( ! $status_obj ) {
				/* translators: 1: Post status, 2: Capability name. */
				$message = __( 'The post status %1$s is not registered, so it may not be reliable to check the capability %2$s against a post with that status.' );

				_doing_it_wrong(
					__FUNCTION__,
					sprintf(
						$message,
						'<code>' . get_post_status( $post ) . '</code>',
						'<code>' . $cap . '</code>'
					),
					'5.4.0'
				);

				$caps[] = 'edit_others_posts';
				break;
			}

			if ( $status_obj->public ) {
				$caps[] = $post_type->cap->read;
				break;
			}

			if ( $post->post_author && $user_id === (int) $post->post_author ) {
				$caps[] = $post_type->cap->read;
			} elseif ( $status_obj->private ) {
				$caps[] = $post_type->cap->read_private_posts;
			} else {
				$caps = map_meta_cap( 'edit_post', $user_id, $post->ID );
			}
			break;
		case 'publish_post':
			if ( ! isset( $args[0] ) ) {
				/* translators: %s: Capability name. */
				$message = __( 'When checking for the %s capability, you must always check it against a specific post.' );

				_doing_it_wrong(
					__FUNCTION__,
					sprintf( $message, '<code>' . $cap . '</code>' ),
					'6.1.0'
				);

				$caps[] = 'do_not_allow';
				break;
			}

			$post = get_post( $args[0] );
			if ( ! $post ) {
				$caps[] = 'do_not_allow';
				break;
			}

			$post_type = get_post_type_object( $post->post_type );
			if ( ! $post_type ) {
				/* translators: 1: Post type, 2: Capability name. */
				$message = __( 'The post type %1$s is not registered, so it may not be reliable to check the capability %2$s against a post of that type.' );

				_doing_it_wrong(
					__FUNCTION__,
					sprintf(
						$message,
						'<code>' . $post->post_type . '</code>',
						'<code>' . $cap . '</code>'
					),
					'4.4.0'
				);

				$caps[] = 'edit_others_posts';
				break;
			}

			$caps[] = $post_type->cap->publish_posts;
			break;
		case 'edit_post_meta':
		case 'delete_post_meta':
		case 'add_post_meta':
		case 'edit_comment_meta':
		case 'delete_comment_meta':
		case 'add_comment_meta':
		case 'edit_term_meta':
		case 'delete_term_meta':
		case 'add_term_meta':
		case 'edit_user_meta':
		case 'delete_user_meta':
		case 'add_user_meta':
			$object_type = explode( '_', $cap )[1];

			if ( ! isset( $args[0] ) ) {
				if ( 'post' === $object_type ) {
					/* translators: %s: Capability name. */
					$message = __( 'When checking for the %s capability, you must always check it against a specific post.' );
				} elseif ( 'comment' === $object_type ) {
					/* translators: %s: Capability name. */
					$message = __( 'When checking for the %s capability, you must always check it against a specific comment.' );
				} elseif ( 'term' === $object_type ) {
					/* translators: %s: Capability name. */
					$message = __( 'When checking for the %s capability, you must always check it against a specific term.' );
				} else {
					/* translators: %s: Capability name. */
					$message = __( 'When checking for the %s capability, you must always check it against a specific user.' );
				}

				_doing_it_wrong(
					__FUNCTION__,
					sprintf( $message, '<code>' . $cap . '</code>' ),
					'6.1.0'
				);

				$caps[] = 'do_not_allow';
				break;
			}

			$object_id = (int) $args[0];

			$object_subtype = get_object_subtype( $object_type, $object_id );

			if ( empty( $object_subtype ) ) {
				$caps[] = 'do_not_allow';
				break;
			}

			$caps = map_meta_cap( "edit_{$object_type}", $user_id, $object_id );

			$meta_key = $args[1] ?? false;

			if ( $meta_key ) {
				$allowed = ! is_protected_meta( $meta_key, $object_type );

				if ( has_filter( "auth_{$object_type}_meta_{$meta_key}_for_{$object_subtype}" ) ) {

					/**
					 * Filters whether the user is allowed to edit a specific meta key of a specific object type and subtype.
					 *
					 * The dynamic portions of the hook name, `$object_type`, `$meta_key`,
					 * and `$object_subtype`, refer to the metadata object type (comment, post, term or user),
					 * the meta key value, and the object subtype respectively.
					 *
					 * @since 4.9.8
					 *
					 * @param bool     $allowed   Whether the user can add the object meta. Default false.
					 * @param string   $meta_key  The meta key.
					 * @param int      $object_id Object ID.
					 * @param int      $user_id   User ID.
					 * @param string   $cap       Capability name.
					 * @param string[] $caps      Array of the user's capabilities.
					 */
					$allowed = apply_filters( "auth_{$object_type}_meta_{$meta_key}_for_{$object_subtype}", $allowed, $meta_key, $object_id, $user_id, $cap, $caps );
				} else {

					/**
					 * Filters whether the user is allowed to edit a specific meta key of a specific object type.
					 *
					 * Return true to have the mapped meta caps from `edit_{$object_type}` apply.
					 *
					 * The dynamic portion of the hook name, `$object_type` refers to the object type being filtered.
					 * The dynamic portion of the hook name, `$meta_key`, refers to the meta key passed to map_meta_cap().
					 *
					 * @since 3.3.0 As `auth_post_meta_{$meta_key}`.
					 * @since 4.6.0
					 *
					 * @param bool     $allowed   Whether the user can add the object meta. Default false.
					 * @param string   $meta_key  The meta key.
					 * @param int      $object_id Object ID.
					 * @param int      $user_id   User ID.
					 * @param string   $cap       Capability name.
					 * @param string[] $caps      Array of the user's capabilities.
					 */
					$allowed = apply_filters( "auth_{$object_type}_meta_{$meta_key}", $allowed, $meta_key, $object_id, $user_id, $cap, $caps );
				}

				/**
				 * Filters whether the user is allowed to edit meta for specific object types/subtypes.
				 *
				 * Return true to have the mapped meta caps from `edit_{$object_type}` apply.
				 *
				 * The dynamic portion of the hook name, `$object_type` refers to the object type being filtered.
				 * The dynamic portion of the hook name, `$object_subtype` refers to the object subtype being filtered.
				 * The dynamic portion of the hook name, `$meta_key`, refers to the meta key passed to map_meta_cap().
				 *
				 * @since 4.6.0 As `auth_post_{$post_type}_meta_{$meta_key}`.
				 * @since 4.7.0 Renamed from `auth_post_{$post_type}_meta_{$meta_key}` to
				 *              `auth_{$object_type}_{$object_subtype}_meta_{$meta_key}`.
				 * @deprecated 4.9.8 Use {@see 'auth_{$object_type}_meta_{$meta_key}_for_{$object_subtype}'} instead.
				 *
				 * @param bool     $allowed   Whether the user can add the object meta. Default false.
				 * @param string   $meta_key  The meta key.
				 * @param int      $object_id Object ID.
				 * @param int      $user_id   User ID.
				 * @param string   $cap       Capability name.
				 * @param string[] $caps      Array of the user's capabilities.
				 */
				$allowed = apply_filters_deprecated(
					"auth_{$object_type}_{$object_subtype}_meta_{$meta_key}",
					array( $allowed, $meta_key, $object_id, $user_id, $cap, $caps ),
					'4.9.8',
					"auth_{$object_type}_meta_{$meta_key}_for_{$object_subtype}"
				);

				if ( ! $allowed ) {
					$caps[] = $cap;
				}
			}
			break;
		case 'edit_comment':
			if ( ! isset( $args[0] ) ) {
				/* translators: %s: Capability name. */
				$message = __( 'When checking for the %s capability, you must always check it against a specific comment.' );

				_doing_it_wrong(
					__FUNCTION__,
					sprintf( $message, '<code>' . $cap . '</code>' ),
					'6.1.0'
				);

				$caps[] = 'do_not_allow';
				break;
			}

			$comment = get_comment( $args[0] );
			if ( ! $comment ) {
				$caps[] = 'do_not_allow';
				break;
			}

			$post = get_post( $comment->comment_post_ID );

			/*
			 * If the post doesn't exist, we have an orphaned comment.
			 * Fall back to the edit_posts capability, instead.
			 */
			if ( $post ) {
				$caps = map_meta_cap( 'edit_post', $user_id, $post->ID );
			} else {
				$caps = map_meta_cap( 'edit_posts', $user_id );
			}
			break;
		case 'unfiltered_upload':
			if ( defined( 'ALLOW_UNFILTERED_UPLOADS' ) && ALLOW_UNFILTERED_UPLOADS && ( ! is_multisite() || is_super_admin( $user_id ) ) ) {
				$caps[] = $cap;
			} else {
				$caps[] = 'do_not_allow';
			}
			break;
		case 'edit_css':
		case 'unfiltered_html':
			// Disallow unfiltered_html for all users, even admins and super admins.
			if ( defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML ) {
				$caps[] = 'do_not_allow';
			} elseif ( is_multisite() && ! is_super_admin( $user_id ) ) {
				$caps[] = 'do_not_allow';
			} else {
				$caps[] = 'unfiltered_html';
			}
			break;
		case 'edit_files':
		case 'edit_plugins':
		case 'edit_themes':
			// Disallow the file editors.
			if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
				$caps[] = 'do_not_allow';
			} elseif ( ! wp_is_file_mod_allowed( 'capability_edit_themes' ) ) {
				$caps[] = 'do_not_allow';
			} elseif ( is_multisite() && ! is_super_admin( $user_id ) ) {
				$caps[] = 'do_not_allow';
			} else {
				$caps[] = $cap;
			}
			break;
		case 'update_plugins':
		case 'delete_plugins':
		case 'install_plugins':
		case 'upload_plugins':
		case 'update_themes':
		case 'delete_themes':
		case 'install_themes':
		case 'upload_themes':
		case 'update_core':
			/*
			 * Disallow anything that creates, deletes, or updates core, plugin, or theme files.
			 * Files in uploads are excepted.
			 */
			if ( ! wp_is_file_mod_allowed( 'capability_update_core' ) ) {
				$caps[] = 'do_not_allow';
			} elseif ( is_multisite() && ! is_super_admin( $user_id ) ) {
				$caps[] = 'do_not_allow';
			} elseif ( 'upload_themes' === $cap ) {
				$caps[] = 'install_themes';
			} elseif ( 'upload_plugins' === $cap ) {
				$caps[] = 'install_plugins';
			} else {
				$caps[] = $cap;
			}
			break;
		case 'install_languages':
		case 'update_languages':
			if ( ! wp_is_file_mod_allowed( 'can_install_language_pack' ) ) {
				$caps[] = 'do_not_allow';
			} elseif ( is_multisite() && ! is_super_admin( $user_id ) ) {
				$caps[] = 'do_not_allow';
			} else {
				$caps[] = 'install_languages';
			}
			break;
		case 'activate_plugins':
		case 'deactivate_plugins':
		case 'activate_plugin':
		case 'deactivate_plugin':
			$caps[] = 'activate_plugins';
			if ( is_multisite() ) {
				// update_, install_, and delete_ are handled above with is_super_admin().
				$menu_perms = get_site_option( 'menu_items', array() );
				if ( empty( $menu_perms['plugins'] ) ) {
					$caps[] = 'manage_network_plugins';
				}
			}
			break;
		case 'resume_plugin':
			$caps[] = 'resume_plugins';
			break;
		case 'resume_theme':
			$caps[] = 'resume_themes';
			break;
		case 'delete_user':
		case 'delete_users':
			// If multisite only super admins can delete users.
			if ( is_multisite() && ! is_super_admin( $user_id ) ) {
				$caps[] = 'do_not_allow';
			} else {
				$caps[] = 'delete_users'; // delete_user maps to delete_users.
			}
			break;
		case 'create_users':
			if ( ! is_multisite() ) {
				$caps[] = $cap;
			} elseif ( is_super_admin( $user_id ) || get_site_option( 'add_new_users' ) ) {
				$caps[] = $cap;
			} else {
				$caps[] = 'do_not_allow';
			}
			break;
		case 'manage_links':
			if ( get_option( 'link_manager_enabled' ) ) {
				$caps[] = $cap;
			} else {
				$caps[] = 'do_not_allow';
			}
			break;
		case 'customize':
			$caps[] = 'edit_theme_options';
			break;
		case 'delete_site':
			if ( is_multisite() ) {
				$caps[] = 'manage_options';
			} else {
				$caps[] = 'do_not_allow';
			}
			break;
		case 'edit_term':
		case 'delete_term':
		case 'assign_term':
			if ( ! isset( $args[0] ) ) {
				/* translators: %s: Capability name. */
				$message = __( 'When checking for the %s capability, you must always check it against a specific term.' );

				_doing_it_wrong(
					__FUNCTION__,
					sprintf( $message, '<code>' . $cap . '</code>' ),
					'6.1.0'
				);

				$caps[] = 'do_not_allow';
				break;
			}

			$term_id = (int) $args[0];
			$term    = get_term( $term_id );
			if ( ! $term || is_wp_error( $term ) ) {
				$caps[] = 'do_not_allow';
				break;
			}

			$tax = get_taxonomy( $term->taxonomy );
			if ( ! $tax ) {
				$caps[] = 'do_not_allow';
				break;
			}

			if ( 'delete_term' === $cap
				&& ( (int) get_option( 'default_' . $term->taxonomy ) === $term->term_id
					|| (int) get_option( 'default_term_' . $term->taxonomy ) === $term->term_id )
			) {
				$caps[] = 'do_not_allow';
				break;
			}

			$taxo_cap = $cap . 's';

			$caps = map_meta_cap( $tax->cap->$taxo_cap, $user_id, $term_id );

			break;
		case 'manage_post_tags':
		case 'edit_categories':
		case 'edit_post_tags':
		case 'delete_categories':
		case 'delete_post_tags':
			$caps[] = 'manage_categories';
			break;
		case 'assign_categories':
		case 'assign_post_tags':
			$caps[] = 'edit_posts';
			break;
		case 'create_sites':
		case 'delete_sites':
		case 'manage_network':
		case 'manage_sites':
		case 'manage_network_users':
		case 'manage_network_plugins':
		case 'manage_network_themes':
		case 'manage_network_options':
		case 'upgrade_network':
			$caps[] = $cap;
			break;
		case 'setup_network':
			if ( is_multisite() ) {
				$caps[] = 'manage_network_options';
			} else {
				$caps[] = 'manage_options';
			}
			break;
		case 'update_php':
			if ( is_multisite() && ! is_super_admin( $user_id ) ) {
				$caps[] = 'do_not_allow';
			} else {
				$caps[] = 'update_core';
			}
			break;
		case 'update_https':
			if ( is_multisite() && ! is_super_admin( $user_id ) ) {
				$caps[] = 'do_not_allow';
			} else {
				$caps[] = 'manage_options';
				$caps[] = 'update_core';
			}
			break;
		case 'export_others_personal_data':
		case 'erase_others_personal_data':
		case 'manage_privacy_options':
			$caps[] = is_multisite() ? 'manage_network' : 'manage_options';
			break;
		case 'create_app_password':
		case 'list_app_passwords':
		case 'read_app_password':
		case 'edit_app_password':
		case 'delete_app_passwords':
		case 'delete_app_password':
			$caps = map_meta_cap( 'edit_user', $user_id, $args[0] );
			break;
		case 'edit_block_binding':
			$block_editor_context = $args[0];
			if ( isset( $block_editor_context->post ) ) {
				$object_id = $block_editor_context->post->ID;
			}
			/*
			 * If the post ID is null, check if the context is the site editor.
			 * Fall back to the edit_theme_options in that case.
			 */
			if ( ! isset( $object_id ) ) {
				if ( ! isset( $block_editor_context->name ) || 'core/edit-site' !== $block_editor_context->name ) {
					$caps[] = 'do_not_allow';
					break;
				}
				$caps = map_meta_cap( 'edit_theme_options', $user_id );
				break;
			}

			$object_subtype = get_object_subtype( 'post', (int) $object_id );
			if ( empty( $object_subtype ) ) {
				$caps[] = 'do_not_allow';
				break;
			}
			$post_type_object = get_post_type_object( $object_subtype );
			// Initialize empty array if it doesn't exist.
			if ( ! isset( $post_type_object->capabilities ) ) {
				$post_type_object->capabilities = array();
			}
			$post_type_capabilities = get_post_type_capabilities( $post_type_object );
			$caps                   = map_meta_cap( $post_type_capabilities->edit_post, $user_id, $object_id );
			break;
		default:
			// Handle meta capabilities for custom post types.
			global $post_type_meta_caps;
			if ( isset( $post_type_meta_caps[ $cap ] ) ) {
				return map_meta_cap( $post_type_meta_caps[ $cap ], $user_id, ...$args );
			}

			// Block capabilities map to their post equivalent.
			$block_caps = array(
				'edit_blocks',
				'edit_others_blocks',
				'publish_blocks',
				'read_private_blocks',
				'delete_blocks',
				'delete_private_blocks',
				'delete_published_blocks',
				'delete_others_blocks',
				'edit_private_blocks',
				'edit_published_blocks',
			);
			if ( in_array( $cap, $block_caps, true ) ) {
				$cap = str_replace( '_blocks', '_posts', $cap );
			}

			// If no meta caps match, return the original cap.
			$caps[] = $cap;
	}

	/**
	 * Filters the primitive capabilities required of the given user to satisfy the
	 * capability being checked.
	 *
	 * @since 2.8.0
	 *
	 * @param string[] $caps    Primitive capabilities required of the user.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id The user ID.
	 * @param array    $args    Adds context to the capability check, typically
	 *                          starting with an object ID.
	 */
	$caps = apply_filters( 'map_meta_cap', $caps, $cap, $user_id, $args );

	/*
	 * Only the filtered result is memoized, so a memo hit returns exactly what the
	 * filter produced. Nothing is stored when the key is empty, and nothing is stored
	 * on the custom post type path above, which returns before reaching this point and
	 * leaves the recursive call it delegates to to memoize under its own key.
	 */
	if ( '' !== $memo_key ) {
		_wp_map_meta_cap_memo( $memo_key, $caps );
	}

	return $caps;
}

/**
 * Builds the canonical memo key for a `map_meta_cap()` call.
 *
 * `map_meta_cap()` is re-entered on every capability check, and a single request asks
 * the same object capability question many times over. Memoizing the answer removes
 * that duplicated work, but only where the answer provably cannot change between two
 * identical calls, so this function returns an empty string, meaning "do not
 * memoize", for every case where it could.
 *
 * A call is not memoized when:
 *
 *  - No further arguments were passed. Those calls resolve through the cheap arms of
 *    the switch, where building and looking up a key costs about as much as the
 *    mapping it would replace.
 *  - `$cap` is not a string, or `$user_id` is not a scalar, so the key could not
 *    describe them faithfully.
 *  - Any further argument is neither scalar nor null. Objects, arrays and resources
 *    cannot be reduced to a key without either serializing them or losing
 *    information, and `edit_block_binding` is passed a block editor context object.
 *  - `$cap` names a metadata capability. `edit_post_meta` and its eleven siblings
 *    resolve through the `auth_{$object_type}_meta_{$meta_key}` filters that
 *    `register_meta()` installs, and through `is_protected_meta()` and
 *    `get_object_subtype_{$object_type}`, none of which fire an action to invalidate
 *    against, so their answer can change part way through a request.
 *  - A `map_meta_cap` callback is registered. A callback may legitimately answer
 *    differently for identical arguments, and core relies on that itself:
 *    `WP_Customize_Manager` adds one for the duration of a single operation and
 *    removes it again afterwards.
 *
 * Every variable-length part of the key is written with its type and its length, so
 * that `array( 1, '2' )`, `array( '1', 2 )` and `array( '1|2' )` cannot collide. The
 * key also carries the current site ID, because `current_user_can_for_site()` and
 * `user_can_for_site()` switch sites around the check, and the sizes of the
 * registries the mapping consults, because `register_post_status()` fires no action
 * at all and the post type, post status and taxonomy registries are also mutated by
 * code that unsets a key directly.
 *
 * @since 7.0.0
 *
 * @ignore
 * @access private
 *
 * @global array      $wp_post_types    Post type registry.
 * @global array      $wp_post_statuses Post status registry.
 * @global array      $wp_taxonomies    Taxonomy registry.
 * @global array|null $super_admins     Super admin logins, when they are defined.
 *
 * @param string $cap     Capability being checked.
 * @param int    $user_id User ID.
 * @param array  $args    Further parameters passed to `map_meta_cap()`.
 * @return string Canonical memo key, or an empty string when the call must not be
 *                memoized.
 */
function _wp_map_meta_cap_memo_key( $cap, $user_id, $args ) {
	global $wp_post_types, $wp_post_statuses, $wp_taxonomies, $super_admins;

	if ( empty( $args )
		|| ! is_string( $cap )
		|| ! is_scalar( $user_id )
		|| str_ends_with( $cap, '_meta' )
		|| has_filter( 'map_meta_cap' )
	) {
		return '';
	}

	$key = get_current_blog_id()
		. '|' . ( is_array( $wp_post_types ) ? count( $wp_post_types ) : -1 )
		. '|' . ( is_array( $wp_post_statuses ) ? count( $wp_post_statuses ) : -1 )
		. '|' . ( is_array( $wp_taxonomies ) ? count( $wp_taxonomies ) : -1 )
		. '|' . ( is_array( $super_admins ) ? count( $super_admins ) : -1 )
		. '|' . strlen( $cap ) . ':' . $cap;

	$user_part = (string) $user_id;
	$key      .= '|' . gettype( $user_id ) . ':' . strlen( $user_part ) . ':' . $user_part;
	$key      .= '|' . count( $args );

	foreach ( $args as $index => $arg ) {
		if ( null !== $arg && ! is_scalar( $arg ) ) {
			return '';
		}

		$arg_part = (string) $arg;
		$key     .= '|' . $index . ':' . gettype( $arg ) . ':' . strlen( $arg_part ) . ':' . $arg_part;
	}

	return $key;
}

/**
 * Reads from, writes to, or discards the request-scoped `map_meta_cap()` memo.
 *
 * The memo lives for the duration of one request only. It is deliberately not written
 * to the object cache: a capability mapping is derived from post, comment, term, user,
 * option and registry state that a persistent, cross-request cache would have no
 * reliable way to invalidate against.
 *
 * @since 7.0.0
 *
 * @ignore
 * @access private
 *
 * @param string        $key  Canonical key from `_wp_map_meta_cap_memo_key()`. Pass an
 *                            empty string to discard everything memoized so far.
 * @param string[]|null $caps Optional. Primitive capabilities to memoize under `$key`.
 *                            Default null, which reads instead of writing.
 * @return string[]|null The memoized capabilities, or null when nothing is memoized
 *                       against `$key`.
 */
function _wp_map_meta_cap_memo( $key, $caps = null ) {
	static $memo = array();

	if ( '' === $key ) {
		$memo = array();

		return null;
	}

	if ( null !== $caps ) {
		$memo[ $key ] = $caps;

		return $caps;
	}

	// array_key_exists() rather than isset(), so a memoized empty result still hits.
	return array_key_exists( $key, $memo ) ? $memo[ $key ] : null;
}

/**
 * Discards the request-scoped `map_meta_cap()` memo.
 *
 * Registered below against the actions that fire once something the mapping reads has
 * already changed, so that the next mapping is computed against the new state. It also
 * keeps the memo from leaking between tests, which matters because `phpunit.xml.dist`
 * sets `backupGlobals="false"` and a single PHP process runs the whole suite.
 *
 * Discarding everything is deliberate. It is one array assignment, and it cannot leave
 * behind a narrower invalidation rule that a later change to one of the mappings would
 * silently outgrow.
 *
 * @since 7.0.0
 *
 * @ignore
 * @access private
 */
function _wp_reset_map_meta_cap_memo() {
	_wp_map_meta_cap_memo( '' );
}

/**
 * Returns whether the current user has the specified capability.
 *
 * This function also accepts an ID of an object to check against if the capability is a meta capability. Meta
 * capabilities such as `edit_post` and `edit_user` are capabilities used by the `map_meta_cap()` function to
 * map to primitive capabilities that a user or role has, such as `edit_posts` and `edit_others_posts`.
 *
 * Example usage:
 *
 *     current_user_can( 'edit_posts' );
 *     current_user_can( 'edit_post', $post->ID );
 *     current_user_can( 'edit_post_meta', $post->ID, $meta_key );
 *
 * While checking against particular roles in place of a capability is supported
 * in part, this practice is discouraged as it may produce unreliable results.
 *
 * Note: Will always return true if the current user is a super admin, unless specifically denied.
 *
 * @since 2.0.0
 * @since 5.3.0 Formalized the existing and already documented `...$args` parameter
 *              by adding it to the function signature.
 * @since 5.8.0 Converted to wrapper for the user_can() function.
 *
 * @see WP_User::has_cap()
 * @see map_meta_cap()
 *
 * @param string $capability Capability name.
 * @param mixed  ...$args    Optional further parameters, typically starting with an object ID.
 * @return bool Whether the current user has the given capability. If `$capability` is a meta cap and `$object_id` is
 *              passed, whether the current user has the given meta capability for the given object.
 */
function current_user_can( $capability, ...$args ) {
	return user_can( wp_get_current_user(), $capability, ...$args );
}

/**
 * Returns whether the current user has the specified capability for a given site.
 *
 * This function also accepts an ID of an object to check against if the capability is a meta capability. Meta
 * capabilities such as `edit_post` and `edit_user` are capabilities used by the `map_meta_cap()` function to
 * map to primitive capabilities that a user or role has, such as `edit_posts` and `edit_others_posts`.
 *
 * This function replaces the current_user_can_for_blog() function.
 *
 * Example usage:
 *
 *     current_user_can_for_site( $site_id, 'edit_posts' );
 *     current_user_can_for_site( $site_id, 'edit_post', $post->ID );
 *     current_user_can_for_site( $site_id, 'edit_post_meta', $post->ID, $meta_key );
 *
 * @since 6.7.0
 *
 * @param int    $site_id    Site ID.
 * @param string $capability Capability name.
 * @param mixed  ...$args    Optional further parameters, typically starting with an object ID.
 * @return bool Whether the user has the given capability.
 */
function current_user_can_for_site( $site_id, $capability, ...$args ) {
	$switched = is_multisite() ? switch_to_blog( $site_id ) : false;

	$can = current_user_can( $capability, ...$args );

	if ( $switched ) {
		restore_current_blog();
	}

	return $can;
}

/**
 * Returns whether the author of the supplied post has the specified capability.
 *
 * This function also accepts an ID of an object to check against if the capability is a meta capability. Meta
 * capabilities such as `edit_post` and `edit_user` are capabilities used by the `map_meta_cap()` function to
 * map to primitive capabilities that a user or role has, such as `edit_posts` and `edit_others_posts`.
 *
 * Example usage:
 *
 *     author_can( $post, 'edit_posts' );
 *     author_can( $post, 'edit_post', $post->ID );
 *     author_can( $post, 'edit_post_meta', $post->ID, $meta_key );
 *
 * @since 2.9.0
 * @since 5.3.0 Formalized the existing and already documented `...$args` parameter
 *              by adding it to the function signature.
 *
 * @param int|WP_Post $post       Post ID or post object.
 * @param string      $capability Capability name.
 * @param mixed       ...$args    Optional further parameters, typically starting with an object ID.
 * @return bool Whether the post author has the given capability.
 */
function author_can( $post, $capability, ...$args ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return false;
	}

	$author = get_userdata( $post->post_author );

	if ( ! $author ) {
		return false;
	}

	return $author->has_cap( $capability, ...$args );
}

/**
 * Returns whether a particular user has the specified capability.
 *
 * This function also accepts an ID of an object to check against if the capability is a meta capability. Meta
 * capabilities such as `edit_post` and `edit_user` are capabilities used by the `map_meta_cap()` function to
 * map to primitive capabilities that a user or role has, such as `edit_posts` and `edit_others_posts`.
 *
 * Example usage:
 *
 *     user_can( $user->ID, 'edit_posts' );
 *     user_can( $user->ID, 'edit_post', $post->ID );
 *     user_can( $user->ID, 'edit_post_meta', $post->ID, $meta_key );
 *
 * @since 3.1.0
 * @since 5.3.0 Formalized the existing and already documented `...$args` parameter
 *              by adding it to the function signature.
 *
 * @param int|WP_User $user       User ID or object.
 * @param string      $capability Capability name.
 * @param mixed       ...$args    Optional further parameters, typically starting with an object ID.
 * @return bool Whether the user has the given capability.
 */
function user_can( $user, $capability, ...$args ) {
	if ( ! is_object( $user ) ) {
		$user = get_userdata( $user );
	}

	if ( empty( $user ) ) {
		// User is logged out, create anonymous user object.
		$user = new WP_User( 0 );
		$user->init( new stdClass() );
	}

	return $user->has_cap( $capability, ...$args );
}

/**
 * Returns whether a particular user has the specified capability for a given site.
 *
 * This function also accepts an ID of an object to check against if the capability is a meta capability. Meta
 * capabilities such as `edit_post` and `edit_user` are capabilities used by the `map_meta_cap()` function to
 * map to primitive capabilities that a user or role has, such as `edit_posts` and `edit_others_posts`.
 *
 * Example usage:
 *
 *     user_can_for_site( $user->ID, $site_id, 'edit_posts' );
 *     user_can_for_site( $user->ID, $site_id, 'edit_post', $post->ID );
 *     user_can_for_site( $user->ID, $site_id, 'edit_post_meta', $post->ID, $meta_key );
 *
 * @since 6.7.0
 *
 * @param int|WP_User $user       User ID or object.
 * @param int         $site_id    Site ID.
 * @param string      $capability Capability name.
 * @param mixed       ...$args    Optional further parameters, typically starting with an object ID.
 * @return bool Whether the user has the given capability.
 */
function user_can_for_site( $user, $site_id, $capability, ...$args ) {
	if ( ! is_object( $user ) ) {
		$user = get_userdata( $user );
	}

	if ( empty( $user ) ) {
		// User is logged out, create anonymous user object.
		$user = new WP_User( 0 );
		$user->init( new stdClass() );
	}

	// Check if the blog ID is valid.
	if ( ! is_numeric( $site_id ) || $site_id <= 0 ) {
		return false;
	}

	$switched = is_multisite() ? switch_to_blog( $site_id ) : false;

	$can = user_can( $user->ID, $capability, ...$args );

	if ( $switched ) {
		restore_current_blog();
	}

	return $can;
}

/**
 * Retrieves the global WP_Roles instance and instantiates it if necessary.
 *
 * @since 4.3.0
 *
 * @global WP_Roles $wp_roles WordPress role management object.
 *
 * @return WP_Roles WP_Roles global instance if not already instantiated.
 */
function wp_roles() {
	global $wp_roles;

	if ( ! isset( $wp_roles ) ) {
		$wp_roles = new WP_Roles();
	}
	return $wp_roles;
}

/**
 * Retrieves role object.
 *
 * @since 2.0.0
 *
 * @param string $role Role name.
 * @return WP_Role|null WP_Role object if found, null if the role does not exist.
 */
function get_role( $role ) {
	return wp_roles()->get_role( $role );
}

/**
 * Adds a role, if it does not exist.
 *
 * The list of capabilities can be passed either as a numerically indexed array of capability names, or an
 * associative array of boolean values keyed by the capability name. To explicitly deny the role a capability, set
 * the value for that capability to false.
 *
 * Examples:
 *
 *     // Add a role that can edit posts.
 *     add_role( 'custom_role', 'Custom Role', array(
 *         'read',
 *         'edit_posts',
 *     ) );
 *
 * Or, using an associative array:
 *
 *     // Add a role that can edit posts but explicitly cannot not delete them.
 *     add_role( 'custom_role', 'Custom Role', array(
 *         'read' => true,
 *         'edit_posts' => true,
 *         'delete_posts' => false,
 *     ) );
 *
 * @since 2.0.0
 * @since 6.9.0 Support was added for a numerically indexed array of strings for the capabilities array.
 *
 * @param string                               $role         Role name.
 * @param string                               $display_name Display name for role.
 * @param array<string,bool>|array<int,string> $capabilities Capabilities to be added to the role.
 *                                                           Default empty array.
 * @return WP_Role|void WP_Role object, if the role is added.
 */
function add_role( $role, $display_name, $capabilities = array() ) {
	if ( empty( $role ) ) {
		return;
	}

	return wp_roles()->add_role( $role, $display_name, $capabilities );
}

/**
 * Removes a role, if it exists.
 *
 * @since 2.0.0
 *
 * @param string $role Role name.
 */
function remove_role( $role ) {
	wp_roles()->remove_role( $role );
}

/**
 * Retrieves a list of super admins.
 *
 * @since 3.0.0
 *
 * @global array $super_admins
 *
 * @return string[] List of super admin logins.
 */
function get_super_admins() {
	global $super_admins;

	if ( isset( $super_admins ) ) {
		return $super_admins;
	} else {
		return get_site_option( 'site_admins', array( 'admin' ) );
	}
}

/**
 * Determines whether user is a site admin.
 *
 * @since 3.0.0
 *
 * @param int|false $user_id Optional. The ID of a user. Defaults to false, to check the current user.
 * @return bool Whether the user is a site admin.
 */
function is_super_admin( $user_id = false ) {
	if ( ! $user_id ) {
		$user = wp_get_current_user();
	} else {
		$user = get_userdata( $user_id );
	}

	if ( ! $user || ! $user->exists() ) {
		return false;
	}

	if ( is_multisite() ) {
		$super_admins = get_super_admins();
		if ( is_array( $super_admins ) && in_array( $user->user_login, $super_admins, true ) ) {
			return true;
		}
	} elseif ( $user->has_cap( 'delete_users' ) ) {
		return true;
	}

	return false;
}

/**
 * Grants Super Admin privileges.
 *
 * @since 3.0.0
 *
 * @global array $super_admins
 *
 * @param int $user_id ID of the user to be granted Super Admin privileges.
 * @return bool True on success, false on failure. This can fail when the user is
 *              already a super admin or when the `$super_admins` global is defined.
 */
function grant_super_admin( $user_id ) {
	// If global super_admins override is defined, there is nothing to do here.
	if ( isset( $GLOBALS['super_admins'] ) || ! is_multisite() ) {
		return false;
	}

	/**
	 * Fires before the user is granted Super Admin privileges.
	 *
	 * @since 3.0.0
	 *
	 * @param int $user_id ID of the user that is about to be granted Super Admin privileges.
	 */
	do_action( 'grant_super_admin', $user_id );

	// Directly fetch site_admins instead of using get_super_admins().
	$super_admins = get_site_option( 'site_admins', array( 'admin' ) );

	$user = get_userdata( $user_id );
	if ( $user && ! in_array( $user->user_login, $super_admins, true ) ) {
		$super_admins[] = $user->user_login;
		update_site_option( 'site_admins', $super_admins );

		/**
		 * Fires after the user is granted Super Admin privileges.
		 *
		 * @since 3.0.0
		 *
		 * @param int $user_id ID of the user that was granted Super Admin privileges.
		 */
		do_action( 'granted_super_admin', $user_id );
		return true;
	}
	return false;
}

/**
 * Revokes Super Admin privileges.
 *
 * @since 3.0.0
 * @since 6.9.0 Super admin privileges can be revoked regardless of email address.
 *
 * @global array $super_admins
 *
 * @param int $user_id ID of the user Super Admin privileges to be revoked from.
 * @return bool True on success, false on failure. This can fail when the user's email
 *              is the network admin email or when the `$super_admins` global is defined.
 */
function revoke_super_admin( $user_id ) {
	// If global super_admins override is defined, there is nothing to do here.
	if ( isset( $GLOBALS['super_admins'] ) || ! is_multisite() ) {
		return false;
	}

	/**
	 * Fires before the user's Super Admin privileges are revoked.
	 *
	 * @since 3.0.0
	 *
	 * @param int $user_id ID of the user Super Admin privileges are being revoked from.
	 */
	do_action( 'revoke_super_admin', $user_id );

	// Directly fetch site_admins instead of using get_super_admins().
	$super_admins = get_site_option( 'site_admins', array( 'admin' ) );

	$user = get_userdata( $user_id );
	if ( $user ) {
		$key = array_search( $user->user_login, $super_admins, true );
		if ( false !== $key ) {
			unset( $super_admins[ $key ] );
			update_site_option( 'site_admins', $super_admins );

			/**
			 * Fires after the user's Super Admin privileges are revoked.
			 *
			 * @since 3.0.0
			 *
			 * @param int $user_id ID of the user Super Admin privileges were revoked from.
			 */
			do_action( 'revoked_super_admin', $user_id );
			return true;
		}
	}
	return false;
}

/**
 * Filters the user capabilities to grant the 'install_languages' capability as necessary.
 *
 * A user must have at least one out of the 'update_core', 'install_plugins', and
 * 'install_themes' capabilities to qualify for 'install_languages'.
 *
 * @since 4.9.0
 *
 * @param bool[] $allcaps An array of all the user's capabilities.
 * @return bool[] Filtered array of the user's capabilities.
 */
function wp_maybe_grant_install_languages_cap( $allcaps ) {
	if ( ! empty( $allcaps['update_core'] ) || ! empty( $allcaps['install_plugins'] ) || ! empty( $allcaps['install_themes'] ) ) {
		$allcaps['install_languages'] = true;
	}

	return $allcaps;
}

/**
 * Filters the user capabilities to grant the 'resume_plugins' and 'resume_themes' capabilities as necessary.
 *
 * @since 5.2.0
 *
 * @param bool[] $allcaps An array of all the user's capabilities.
 * @return bool[] Filtered array of the user's capabilities.
 */
function wp_maybe_grant_resume_extensions_caps( $allcaps ) {
	// Even in a multisite, regular administrators should be able to resume plugins.
	if ( ! empty( $allcaps['activate_plugins'] ) ) {
		$allcaps['resume_plugins'] = true;
	}

	// Even in a multisite, regular administrators should be able to resume themes.
	if ( ! empty( $allcaps['switch_themes'] ) ) {
		$allcaps['resume_themes'] = true;
	}

	return $allcaps;
}

/**
 * Filters the user capabilities to grant the 'view_site_health_checks' capabilities as necessary.
 *
 * @since 5.2.2
 *
 * @param bool[]   $allcaps An array of all the user's capabilities.
 * @param string[] $caps    Required primitive capabilities for the requested capability.
 * @param array    $args {
 *     Arguments that accompany the requested capability check.
 *
 *     @type string    $0 Requested capability.
 *     @type int       $1 Concerned user ID.
 *     @type mixed  ...$2 Optional second and further parameters, typically object ID.
 * }
 * @param WP_User  $user    The user object.
 * @return bool[] Filtered array of the user's capabilities.
 */
function wp_maybe_grant_site_health_caps( $allcaps, $caps, $args, $user ) {
	if ( ! empty( $allcaps['install_plugins'] ) && ( ! is_multisite() || is_super_admin( $user->ID ) ) ) {
		$allcaps['view_site_health_checks'] = true;
	}

	return $allcaps;
}

/*
 * Discards the request-scoped map_meta_cap() memo on the state changes enumerated below.
 * Each of those actions fires after its mutation has been applied, which is why the super
 * admin pair is granted_super_admin/revoked_super_admin rather than the
 * grant_super_admin/revoke_super_admin actions that fire beforehand. State a mapping reads
 * without passing through one of these actions is handled by the memo key instead, which
 * carries the registry sizes that register_post_status() and its siblings change without
 * firing an action of their own. Discarding costs no more than a recomputation, whereas
 * retaining a mapping that no longer holds would be an authorization bug.
 *
 * These registrations live here rather than in default-filters.php because the memo is
 * an implementation detail of this file, following the same pattern as the file-scope
 * add_action() call at the end of block-patterns.php.
 */
foreach (
	array(
		// Post author, status, type and parent, and the trashed status stored in meta.
		'clean_post_cache',
		'added_post_meta',
		'updated_post_meta',
		'deleted_post_meta',
		// Comment, term and user objects read by the comment, term and user mappings.
		'clean_comment_cache',
		'clean_term_cache',
		'clean_user_cache',
		// Role membership, which decides which primitive capabilities a user holds.
		'set_user_role',
		'add_user_role',
		'remove_user_role',
		// Super admin membership, once the change has been stored.
		'granted_super_admin',
		'revoked_super_admin',
		// The capability maps registered for post types and taxonomies.
		'registered_post_type',
		'unregistered_post_type',
		'registered_taxonomy',
		'unregistered_taxonomy',
		// Options and network options that individual mappings compare against.
		'added_option',
		'updated_option',
		'deleted_option',
		'add_site_option',
		'update_site_option',
		'delete_site_option',
		// Site context, for the current_user_can_for_site() family.
		'switch_blog',
	) as $hook
) {
	add_action( $hook, '_wp_reset_map_meta_cap_memo', 10, 0 );
}
unset( $hook );

return;

// Dummy gettext calls to get strings in the catalog.
/* translators: User role for administrators. */
_x( 'Administrator', 'User role' );
/* translators: User role for editors. */
_x( 'Editor', 'User role' );
/* translators: User role for authors. */
_x( 'Author', 'User role' );
/* translators: User role for contributors. */
_x( 'Contributor', 'User role' );
/* translators: User role for subscribers. */
_x( 'Subscriber', 'User role' );
