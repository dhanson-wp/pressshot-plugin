<?php
/**
 * Deleting a Library folder.
 *
 * Creating, renaming, nesting, and listing folders all go through WordPress's own
 * /wp/v2/pressshot-folders routes. Deleting needs a choice WordPress doesn't have: keep what's inside
 * (move it up or over to another folder) or remove everything in it. This route does both, and only
 * after the caller types the confirmation word.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Ids of the folder and every folder inside it, deepest first. */
function pressshot_folder_tree( $folder_id ) {
	$ids = array_map( 'intval', get_term_children( $folder_id, 'pressshot_folder' ) );
	usort(
		$ids,
		function ( $a, $b ) {
			return count( get_ancestors( $b, 'pressshot_folder', 'taxonomy' ) ) - count( get_ancestors( $a, 'pressshot_folder', 'taxonomy' ) );
		}
	);
	$ids[] = (int) $folder_id;
	return $ids;
}

/** Ids of the shots filed directly in any of the given folders. */
function pressshot_shots_in_folders( array $folder_ids ) {
	$objects = get_objects_in_term( $folder_ids, 'pressshot_folder' );
	if ( is_wp_error( $objects ) ) {
		return array();
	}
	return array_values( array_filter( array_map( 'intval', $objects ), 'pressshot_is_shot' ) );
}

function pressshot_delete_folder( WP_REST_Request $request ) {
	$folder = get_term( (int) $request['id'], 'pressshot_folder' );
	if ( ! $folder || is_wp_error( $folder ) ) {
		return new WP_Error( 'pressshot_not_found', __( 'Folder not found.', 'pressshot' ), array( 'status' => 404 ) );
	}
	if ( 'DELETE' !== $request->get_param( 'confirm' ) ) {
		return new WP_Error( 'pressshot_confirm', __( 'Type DELETE to confirm.', 'pressshot' ), array( 'status' => 400 ) );
	}
	$mode = $request->get_param( 'mode' );
	$tree = pressshot_folder_tree( $folder->term_id );

	if ( 'everything' === $mode ) {
		if ( ! EMPTY_TRASH_DAYS ) {
			return new WP_Error( 'pressshot_trash_off', __( 'Trash is switched off on this site.', 'pressshot' ), array( 'status' => 409 ) );
		}
		$shots = pressshot_shots_in_folders( $tree );
		$mine  = 0;
		foreach ( $shots as $shot_id ) {
			if ( ! pressshot_is_trashed( $shot_id ) && ! current_user_can( 'delete_post', $shot_id ) ) {
				++$mine;
			}
		}
		if ( $mine ) {
			return new WP_Error(
				'pressshot_folder_not_yours',
				__( 'Some shots in this folder belong to other people, so you can\'t delete everything in it.', 'pressshot' ),
				array(
					'status' => 403,
					'count'  => $mine,
				)
			);
		}
		$trashed = 0;
		foreach ( $shots as $shot_id ) {
			// Drop the folder first so a restored shot comes back unfiled.
			wp_set_object_terms( $shot_id, array(), 'pressshot_folder' );
			if ( pressshot_is_trashed( $shot_id ) ) {
				continue;
			}
			if ( ! wp_trash_post( $shot_id ) ) {
				return new WP_Error( 'pressshot_trash_failed', __( 'A shot couldn\'t be moved to Trash.', 'pressshot' ), array( 'status' => 500 ) );
			}
			++$trashed;
		}
		$deleted = array();
		foreach ( $tree as $term_id ) {
			if ( true === wp_delete_term( $term_id, 'pressshot_folder' ) ) {
				$deleted[] = $term_id;
			}
		}
		if ( count( $deleted ) !== count( $tree ) ) {
			return new WP_Error(
				'pressshot_delete_failed',
				__( 'Some folders couldn\'t be deleted.', 'pressshot' ),
				array(
					'status'  => 500,
					'deleted' => $deleted,
					'trashed' => $trashed,
				)
			);
		}
		return rest_ensure_response(
			array(
				'deleted' => $deleted,
				'moved'   => 0,
				'trashed' => $trashed,
			)
		);
	}

	if ( 'move' !== $mode ) {
		return new WP_Error( 'pressshot_bad_mode', __( 'Choose to move the contents or delete everything.', 'pressshot' ), array( 'status' => 400 ) );
	}

	$target = (int) $request->get_param( 'target' );
	if ( $target && ( in_array( $target, $tree, true ) || ! get_term( $target, 'pressshot_folder' ) || is_wp_error( get_term( $target, 'pressshot_folder' ) ) ) ) {
		return new WP_Error( 'pressshot_bad_target', __( 'Pick a different folder to move the contents into.', 'pressshot' ), array( 'status' => 400 ) );
	}
	$children = get_terms(
		array(
			'taxonomy'   => 'pressshot_folder',
			'parent'     => $folder->term_id,
			'hide_empty' => false,
		)
	);
	$children = is_wp_error( $children ) ? array() : $children;
	// Moving the contents somewhere a live link covers shares them, so it takes what sharing takes.
	if ( $children ) {
		$exposure = pressshot_check_folder_exposure( $folder->term_id, $target );
		if ( is_wp_error( $exposure ) ) {
			return $exposure;
		}
	}
	$exposure = pressshot_check_shot_exposure( pressshot_shots_in_folders( array( $folder->term_id ) ), $target );
	if ( is_wp_error( $exposure ) ) {
		return $exposure;
	}
	// A folder can't sit beside another with the same name. Check first so nothing changes if one would.
	foreach ( $children as $child ) {
		$clash = get_terms(
			array(
				'taxonomy'   => 'pressshot_folder',
				'name'       => $child->name,
				'parent'     => $target,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( ! is_wp_error( $clash ) && $clash ) {
			return new WP_Error(
				'pressshot_folder_name_taken',
				/* translators: %s: folder name */
				sprintf( __( 'The destination already has a folder named "%s".', 'pressshot' ), $child->name ),
				array( 'status' => 409 )
			);
		}
	}
	foreach ( $children as $child ) {
		$updated = wp_update_term( (int) $child->term_id, 'pressshot_folder', array( 'parent' => $target ) );
		if ( is_wp_error( $updated ) ) {
			return new WP_Error( 'pressshot_move_failed', $updated->get_error_message(), array( 'status' => 500 ) );
		}
	}
	$moved = 0;
	foreach ( pressshot_shots_in_folders( array( $folder->term_id ) ) as $shot_id ) {
		wp_set_object_terms( $shot_id, $target ? array( $target ) : array(), 'pressshot_folder' );
		++$moved;
	}
	if ( true !== wp_delete_term( $folder->term_id, 'pressshot_folder' ) ) {
		return new WP_Error( 'pressshot_delete_failed', __( 'The folder couldn\'t be deleted.', 'pressshot' ), array( 'status' => 500 ) );
	}
	return rest_ensure_response(
		array(
			'deleted' => array( (int) $folder->term_id ),
			'moved'   => $moved,
			'trashed' => 0,
		)
	);
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'pressshot/v1',
			'/folders/(?P<id>[1-9][0-9]*)/delete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'pressshot_delete_folder',
				'permission_callback' => 'pressshot_library_permission',
			)
		);
	}
);
