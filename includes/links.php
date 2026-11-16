<?php
/**
 * Anyone-with-the-link sharing for shots and folders.
 *
 * A link is a record of the private post type pressshot_link with meta for its kind (shot or
 * folder), its target id, a 32-character token, and an expiry. A target has at most one active
 * link. Tokens are stored readable so teammates can copy the link again later; they are only
 * ever exposed to Library users (the DTO and the link routes), never to anonymous callers beyond
 * the URL the visitor already holds.
 *
 * The two anonymous routes at the bottom are the only routes in the plugin that don't require
 * Library access. Every failure on them returns the identical 404 from pressshot_link_unavailable().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PRESSSHOT_LINK_POST_TYPE = 'pressshot_link';

/** The most shots a folder link lists. A folder holding more shows its 500 newest. */
const PRESSSHOT_LINK_MAX_SHOTS = 500;

add_action(
	'init',
	function () {
		register_post_type(
			PRESSSHOT_LINK_POST_TYPE,
			array(
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'show_in_menu'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'supports'            => array( 'title' ),
			)
		);
	}
);

/** Link records for a target, newest first. */
function pressshot_link_post_ids( $kind, $target ) {
	return get_posts(
		array(
			'post_type'        => PRESSSHOT_LINK_POST_TYPE,
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'DESC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => array(
				'relation' => 'AND',
				array( 'key' => '_pressshot_link_kind', 'value' => $kind ),
				array( 'key' => '_pressshot_link_target', 'value' => (string) (int) $target ),
			),
		)
	);
}

/** The link array for a link record id. */
function pressshot_link_from_post( $post_id ) {
	$expires = (int) get_post_meta( $post_id, '_pressshot_link_expires', true );
	$token   = (string) get_post_meta( $post_id, '_pressshot_link_token', true );
	return array(
		'id'        => (int) $post_id,
		'kind'      => (string) get_post_meta( $post_id, '_pressshot_link_kind', true ),
		'target'    => (int) get_post_meta( $post_id, '_pressshot_link_target', true ),
		'token'     => $token,
		'url'       => pressshot_link_url( $token ),
		'expiresAt' => $expires > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $expires ) : null,
	);
}

function pressshot_link_url( $token ) {
	return home_url( '/s/' . $token );
}

/** The target's active link, or null. This ignores expiry so a Library user can see and renew it. */
function pressshot_link_for( $kind, $target ) {
	$links = pressshot_links_for( $kind, array( $target ) );
	return isset( $links[ (int) $target ] ) ? $links[ (int) $target ] : null;
}

/**
 * Each target's active link (its newest record), for many targets in one query. Like
 * pressshot_link_for(), this ignores expiry.
 *
 * @return array<int, array> Target id => link. Targets without a link are left out.
 */
function pressshot_links_for( $kind, array $targets ) {
	$targets = array_values( array_unique( array_filter( array_map( 'intval', $targets ) ) ) );
	if ( ! $targets ) {
		return array();
	}
	$ids = get_posts(
		array(
			'post_type'        => PRESSSHOT_LINK_POST_TYPE,
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'DESC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => array(
				'relation' => 'AND',
				array( 'key' => '_pressshot_link_kind', 'value' => $kind ),
				array( 'key' => '_pressshot_link_target', 'value' => array_map( 'strval', $targets ), 'compare' => 'IN' ),
			),
		)
	);
	update_meta_cache( 'post', $ids );
	$links = array();
	foreach ( $ids as $post_id ) {
		$link = pressshot_link_from_post( $post_id );
		if ( ! isset( $links[ $link['target'] ] ) ) {
			$links[ $link['target'] ] = $link;
		}
	}
	return $links;
}

/**
 * Folders whose link works right now (published and not expired), in one query.
 *
 * @return array<int, array> Folder term id => link.
 */
function pressshot_live_folder_links() {
	$ids = get_posts(
		array(
			'post_type'        => PRESSSHOT_LINK_POST_TYPE,
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'DESC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => array(
				array( 'key' => '_pressshot_link_kind', 'value' => 'folder' ),
			),
		)
	);
	update_meta_cache( 'post', $ids );
	$links = array();
	foreach ( $ids as $post_id ) {
		$expires = (int) get_post_meta( $post_id, '_pressshot_link_expires', true );
		if ( $expires > 0 && time() >= $expires ) {
			continue;
		}
		$link = pressshot_link_from_post( $post_id );
		if ( ! isset( $links[ $link['target'] ] ) ) {
			$links[ $link['target'] ] = $link;
		}
	}
	return $links;
}

/**
 * The folder whose live link also shares a shot in the given folder: that folder itself or the
 * nearest ancestor with a live link. Null when none does.
 *
 * @param int   $folder_id  The shot's folder term id (0 for none).
 * @param array $live_links From pressshot_live_folder_links().
 * @return WP_Term|null
 */
function pressshot_sharing_folder( $folder_id, array $live_links ) {
	$folder_id = (int) $folder_id;
	if ( $folder_id < 1 || ! $live_links ) {
		return null;
	}
	$chain = array_merge( array( $folder_id ), array_map( 'intval', get_ancestors( $folder_id, 'pressshot_folder', 'taxonomy' ) ) );
	foreach ( $chain as $term_id ) {
		if ( isset( $live_links[ $term_id ] ) ) {
			$term = get_term( $term_id, 'pressshot_folder' );
			return $term instanceof WP_Term ? $term : null;
		}
	}
	return null;
}

function pressshot_link_new_token() {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$token = '';
	for ( $i = 0; $i < 32; $i++ ) {
		$token .= $chars[ random_int( 0, 61 ) ];
	}
	return $token;
}

/** Returns the target's existing link (updating its expiry) or makes one. A WP_Error (status 500) when the record can't be saved. */
function pressshot_link_create( $kind, $target, $expires = 0 ) {
	$target  = (int) $target;
	$expires = max( 0, (int) $expires );
	$ids     = pressshot_link_post_ids( $kind, $target );
	if ( $ids ) {
		update_post_meta( $ids[0], '_pressshot_link_expires', $expires );
		return pressshot_link_from_post( $ids[0] );
	}
	$post_id = wp_insert_post(
		array(
			'post_type'   => PRESSSHOT_LINK_POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $kind . ' ' . $target,
		),
		true
	);
	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return new WP_Error( 'pressshot_link_failed', __( 'Couldn\'t make the link. Try again.', 'pressshot' ), array( 'status' => 500 ) );
	}
	update_post_meta( $post_id, '_pressshot_link_kind', $kind );
	update_post_meta( $post_id, '_pressshot_link_target', $target );
	update_post_meta( $post_id, '_pressshot_link_token', pressshot_link_new_token() );
	update_post_meta( $post_id, '_pressshot_link_expires', $expires );
	return pressshot_link_from_post( $post_id );
}

/** Deletes every link record for the target. Revoking twice is fine. */
function pressshot_link_revoke( $kind, $target ) {
	foreach ( pressshot_link_post_ids( $kind, (int) $target ) as $post_id ) {
		wp_delete_post( $post_id, true );
	}
}

/** The live link for a token, or null for anything that shouldn't resolve. */
function pressshot_link_resolve( $token ) {
	if ( ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9]{32}$/D', $token ) ) {
		return null;
	}
	$ids = get_posts(
		array(
			'post_type'        => PRESSSHOT_LINK_POST_TYPE,
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => array(
				array( 'key' => '_pressshot_link_token', 'value' => $token ),
			),
		)
	);
	if ( ! $ids ) {
		return null;
	}
	$link = pressshot_link_from_post( $ids[0] );
	if ( ! hash_equals( $link['token'], $token ) ) {
		return null;
	}
	$expires = (int) get_post_meta( $ids[0], '_pressshot_link_expires', true );
	if ( $expires > 0 && time() >= $expires ) {
		return null;
	}
	if ( 'shot' === $link['kind'] ) {
		if ( ! pressshot_is_shot( $link['target'] ) || pressshot_is_trashed( $link['target'] ) ) {
			return null;
		}
	} elseif ( 'folder' === $link['kind'] ) {
		$term = get_term( $link['target'], 'pressshot_folder' );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
	} else {
		return null;
	}
	return $link;
}

/** Whether the link lets its visitor see the shot. Folder links cover the folder and everything under it. */
function pressshot_link_covers_shot( array $link, $shot_id ) {
	$shot_id = (int) $shot_id;
	if ( ! pressshot_is_shot( $shot_id ) || pressshot_is_trashed( $shot_id ) ) {
		return false;
	}
	if ( 'shot' === $link['kind'] ) {
		return (int) $link['target'] === $shot_id;
	}
	if ( 'folder' !== $link['kind'] ) {
		return false;
	}
	$terms = wp_get_object_terms( $shot_id, 'pressshot_folder', array( 'fields' => 'ids' ) );
	if ( is_wp_error( $terms ) ) {
		return false;
	}
	foreach ( $terms as $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id === (int) $link['target'] ) {
			return true;
		}
		if ( in_array( (int) $link['target'], array_map( 'intval', get_ancestors( $term_id, 'pressshot_folder', 'taxonomy' ) ), true ) ) {
			return true;
		}
	}
	return false;
}

// Trashing, deleting, or removing the target ends its link, so restoring never revives an old one.
add_action(
	'trashed_post',
	function ( $post_id ) {
		pressshot_link_revoke( 'shot', $post_id );
	}
);
add_action(
	'delete_attachment',
	function ( $post_id ) {
		pressshot_link_revoke( 'shot', $post_id );
	}
);
add_action(
	'delete_pressshot_folder',
	function ( $term_id ) {
		pressshot_link_revoke( 'folder', $term_id );
	}
);

/** Parses the expiresAt body value into Unix seconds (0 = never), or a 400. */
function pressshot_link_parse_expiry( WP_REST_Request $request ) {
	$value = $request->get_param( 'expiresAt' );
	if ( null === $value || '' === $value ) {
		return 0;
	}
	$time = is_string( $value ) ? strtotime( $value ) : false;
	if ( false === $time ) {
		return new WP_Error( 'pressshot_bad_expiry', __( 'That expiry date isn\'t valid.', 'pressshot' ), array( 'status' => 400 ) );
	}
	if ( $time <= time() ) {
		return new WP_Error( 'pressshot_bad_expiry', __( 'The expiry has to be in the future.', 'pressshot' ), array( 'status' => 400 ) );
	}
	return $time;
}

/** Library access, a live shot, and permission to edit it. */
function pressshot_shot_link_permission( WP_REST_Request $request ) {
	$access = pressshot_library_permission();
	if ( is_wp_error( $access ) ) {
		return $access;
	}
	$id = (int) $request['id'];
	if ( ! pressshot_is_shot( $id ) || pressshot_is_trashed( $id ) ) {
		return new WP_Error( 'pressshot_not_found', __( 'Shot not found.', 'pressshot' ), array( 'status' => 404 ) );
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return new WP_Error( 'pressshot_forbidden', __( 'You can\'t change this shot.', 'pressshot' ), array( 'status' => 403 ) );
	}
	return true;
}

/** Library access, an existing folder, and permission to edit others' content. */
function pressshot_folder_link_permission( WP_REST_Request $request ) {
	$access = pressshot_library_permission();
	if ( is_wp_error( $access ) ) {
		return $access;
	}
	$term = get_term( (int) $request['id'], 'pressshot_folder' );
	if ( ! $term || is_wp_error( $term ) ) {
		return new WP_Error( 'pressshot_not_found', __( 'Folder not found.', 'pressshot' ), array( 'status' => 404 ) );
	}
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return new WP_Error( 'pressshot_forbidden', __( 'You can\'t share folders.', 'pressshot' ), array( 'status' => 403 ) );
	}
	return true;
}

function pressshot_set_link( $kind, WP_REST_Request $request ) {
	$expires = pressshot_link_parse_expiry( $request );
	if ( is_wp_error( $expires ) ) {
		return $expires;
	}
	$link = pressshot_link_create( $kind, (int) $request['id'], $expires );
	return is_wp_error( $link ) ? $link : rest_ensure_response( $link );
}

function pressshot_remove_link( $kind, WP_REST_Request $request ) {
	pressshot_link_revoke( $kind, (int) $request['id'] );
	return rest_ensure_response( array( 'link' => null ) );
}

/** The one 404 every anonymous failure returns, so a visitor can't tell why a link doesn't work. */
function pressshot_link_unavailable() {
	return new WP_Error( 'pressshot_link_unavailable', __( 'This link is no longer available.', 'pressshot' ), array( 'status' => 404 ) );
}

/** What a visitor sees of one shot. Media URLs carry the token. */
function pressshot_public_shot( $id, $token ) {
	$id    = (int) $id;
	$post  = get_post( $id );
	$mime  = (string) get_post_mime_type( $id );
	$meta  = wp_get_attachment_metadata( $id );
	$meta  = is_array( $meta ) ? $meta : array();
	$guid  = pressshot_videopress_guid( $id );
	$local = ! pressshot_is_videopress_only( $id );
	$base  = rest_url( 'pressshot/v1/links/' . $token . '/shots/' . $id . '/media' );
	return array(
		'id'             => $id,
		'title'          => $post->post_title,
		'kind'           => 0 === strpos( $mime, 'video/' ) ? 'recording' : 'screenshot',
		'mime'           => $mime,
		'width'          => isset( $meta['width'] ) ? (int) $meta['width'] : null,
		'height'         => isset( $meta['height'] ) ? (int) $meta['height'] : null,
		'videopressGuid' => $guid,
		'mediaUrl'       => $local ? $base : null,
		'downloadUrl'    => $local ? add_query_arg( 'download', '1', $base ) : null,
		'posterUrl'      => $guid && pressshot_videopress_poster( $id ) ? rest_url( 'pressshot/v1/links/' . $token . '/shots/' . $id . '/poster' ) : null,
	);
}

/**
 * What a live link shows: { kind, title, shots: PublicShot[], folders }. A folder link lists up to
 * PRESSSHOT_LINK_MAX_SHOTS shots, newest first. The REST route and the share page both use this.
 */
function pressshot_link_view( array $link ) {
	$token = $link['token'];
	if ( 'shot' === $link['kind'] ) {
		return array(
			'kind'    => 'shot',
			'title'   => get_the_title( $link['target'] ),
			'shots'   => array( pressshot_public_shot( $link['target'], $token ) ),
			'folders' => array(),
		);
	}
	$folder = get_term( $link['target'], 'pressshot_folder' );
	// Full posts, not ids, so WP_Query primes the post, meta, and term caches for the whole page at once.
	$query  = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => PRESSSHOT_LINK_MAX_SHOTS,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => PRESSSHOT_SHOT_META, 'value' => '1' ) ),
			'tax_query'      => array(
				array(
					'taxonomy'         => 'pressshot_folder',
					'field'            => 'term_id',
					'terms'            => array( (int) $folder->term_id ),
					'include_children' => true,
				),
			),
		)
	);
	$folders = array(
		array(
			'id'     => (int) $folder->term_id,
			'name'   => $folder->name,
			'parent' => (int) $folder->parent,
		),
	);
	foreach ( get_terms( array( 'taxonomy' => 'pressshot_folder', 'child_of' => $folder->term_id, 'hide_empty' => false ) ) as $child ) {
		$folders[] = array(
			'id'     => (int) $child->term_id,
			'name'   => $child->name,
			'parent' => (int) $child->parent,
		);
	}
	return array(
		'kind'    => 'folder',
		'title'   => $folder->name,
		'shots'   => array_map(
			function ( $id ) use ( $token ) {
				return pressshot_public_shot( $id, $token );
			},
			wp_list_pluck( $query->posts, 'ID' )
		),
		'folders' => $folders,
	);
}

/**
 * Marks an anonymous response as private and unindexed, success or failure, so a hosted page
 * cache can't keep serving a revoked link. A media response keeps its own stricter Cache-Control.
 */
function pressshot_link_private_response( $result ) {
	$response = is_wp_error( $result ) ? rest_convert_error_to_response( $result ) : rest_ensure_response( $result );
	if ( empty( $response->get_headers()['Cache-Control'] ) ) {
		$response->header( 'Cache-Control', 'private, no-store' );
	}
	$response->header( 'X-Robots-Tag', 'noindex' );
	return $response;
}

/** Anonymous: what a link shows. */
function pressshot_get_link( WP_REST_Request $request ) {
	$link = pressshot_link_resolve( (string) $request['token'] );
	if ( ! $link ) {
		return pressshot_link_private_response( pressshot_link_unavailable() );
	}
	return pressshot_link_private_response( pressshot_link_view( $link ) );
}

/** Anonymous: streams a shot's media when the link covers it. */
function pressshot_get_link_media( WP_REST_Request $request ) {
	$link    = pressshot_link_resolve( (string) $request['token'] );
	$shot_id = (int) $request['shot'];
	if ( ! $link || ! ctype_digit( (string) $request['shot'] ) || ! pressshot_link_covers_shot( $link, $shot_id ) ) {
		return pressshot_link_private_response( pressshot_link_unavailable() );
	}
	// Any size other than "medium" means the full file.
	$response = pressshot_stream_file( $shot_id, $request, 'medium' === $request['size'] ? 'medium' : '' );
	if ( is_wp_error( $response ) && 404 === ( $response->get_error_data()['status'] ?? 0 ) ) {
		$response = pressshot_link_unavailable();
	}
	if ( $response instanceof PressShot_Media_Response ) {
		$response->limit_time( pressshot_link_media_time_limit( (string) ( $response->get_headers()['Content-Type'] ?? '' ) ) );
	}
	return pressshot_link_private_response( $response );
}

/** How long a share link's image may take to send, in seconds, before PHP gives up on the client. */
const PRESSSHOT_LINK_IMAGE_TIME_LIMIT = 30;

/**
 * The time cap for streaming a share link's media to anyone who has the link, signed in or not.
 * Images get PRESSSHOT_LINK_IMAGE_TIME_LIMIT, which is plenty for a screenshot and stops a slow
 * client from holding a PHP worker. Recordings stay uncapped (0) for now, since a long one can
 * rightly take minutes. The pressshot_link_media_time_limit filter can change either.
 */
function pressshot_link_media_time_limit( $mime ) {
	$seconds = 0 === strpos( $mime, 'image/' ) ? PRESSSHOT_LINK_IMAGE_TIME_LIMIT : 0;
	return max( 0, (int) apply_filters( 'pressshot_link_media_time_limit', $seconds, $mime ) );
}

/**
 * Anonymous: sends a visitor to a recording's VideoPress still when the link covers it. The pass is
 * minted only here, per image request, so a revoked or expired link gets the one 404 and no pass.
 */
function pressshot_get_link_poster( WP_REST_Request $request ) {
	$link    = pressshot_link_resolve( (string) $request['token'] );
	$shot_id = (int) $request['shot'];
	if ( ! $link || ! ctype_digit( (string) $request['shot'] ) || ! pressshot_link_covers_shot( $link, $shot_id ) ) {
		return pressshot_link_private_response( pressshot_link_unavailable() );
	}
	$guid   = pressshot_videopress_guid( $shot_id );
	$poster = $guid ? pressshot_videopress_poster( $shot_id ) : null;
	if ( ! $poster ) {
		return pressshot_link_private_response( pressshot_link_unavailable() );
	}
	return pressshot_link_private_response( pressshot_poster_redirect( $guid, $poster ) );
}

add_action(
	'rest_api_init',
	function () {
		$ns     = 'pressshot/v1';
		$expiry = array( 'expiresAt' => array( 'description' => 'ISO 8601 time the link stops working, or null for never.' ) );
		foreach ( array( 'shot' => 'shots', 'folder' => 'folders' ) as $kind => $base ) {
			register_rest_route(
				$ns,
				'/' . $base . '/(?P<id>[1-9][0-9]*)/link',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function ( WP_REST_Request $request ) use ( $kind ) {
							return rest_ensure_response( array( 'link' => pressshot_link_for( $kind, (int) $request['id'] ) ) );
						},
						'permission_callback' => 'pressshot_' . $kind . '_link_permission',
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => function ( WP_REST_Request $request ) use ( $kind ) {
							return pressshot_set_link( $kind, $request );
						},
						'permission_callback' => 'pressshot_' . $kind . '_link_permission',
						'args'                => $expiry,
					),
					array(
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => function ( WP_REST_Request $request ) use ( $kind ) {
							return pressshot_remove_link( $kind, $request );
						},
						'permission_callback' => 'pressshot_' . $kind . '_link_permission',
					),
				)
			);
		}
		// The token patterns accept anything so a malformed token gets our 404, not the router's.
		register_rest_route(
			$ns,
			'/links/(?P<token>[^/]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'pressshot_get_link',
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/links/(?P<token>[^/]+)/shots/(?P<shot>[^/]+)/media',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'pressshot_get_link_media',
				'permission_callback' => '__return_true',
				// Deliberately no args: the router must not reject a bad size or download value. Any size but "medium" is the full file, and every failure is our one 404.
			)
		);
		register_rest_route(
			$ns,
			'/links/(?P<token>[^/]+)/shots/(?P<shot>[^/]+)/poster',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'pressshot_get_link_poster',
				'permission_callback' => '__return_true',
			)
		);
	}
);
