<?php
/**
 * The Library's read and organize API: list shots, read one, stream its media, and file shots
 * into folders and tags. Every route requires Library access (pressshot_can_use_library()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shape every Library route returns for one shot.
 *
 * @param int        $id      The shot id.
 * @param array|null $context Lookups shared across a page of shots, from pressshot_shot_dto_context(). Built for this one shot when null.
 */
function pressshot_shot_dto( $id, $context = null ) {
	$id       = (int) $id;
	$context  = is_array( $context ) ? $context : pressshot_shot_dto_context( array( $id ) );
	$post     = get_post( $id );
	$mime     = (string) get_post_mime_type( $id );
	$meta     = wp_get_attachment_metadata( $id );
	$meta     = is_array( $meta ) ? $meta : array();
	$author   = get_userdata( (int) $post->post_author );
	// get_the_terms() reads the object term cache that pressshot_shot_dto_context() primed.
	$folders  = get_the_terms( $id, 'pressshot_folder' );
	$folders  = is_array( $folders ) ? wp_list_pluck( $folders, 'term_id' ) : array();
	$tags     = get_the_terms( $id, 'pressshot_tag' );
	$tags     = is_array( $tags ) ? wp_list_pluck( $tags, 'term_id' ) : array();
	$library  = pressshot_can_use_library();
	$folder   = $folders ? (int) $folders[0] : 0;
	$sharing  = $library ? pressshot_sharing_folder( $folder, $context['folder_links'] ) : null;
	$guid     = pressshot_videopress_guid( $id );
	$local    = ! pressshot_is_videopress_only( $id );
	$file     = get_attached_file( $id );
	$bytes    = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : ( $file && is_file( $file ) ? (int) filesize( $file ) : null );
	$base_url = rest_url( 'pressshot/v1/shots/' . $id . '/media' );

	return array(
		'id'             => $id,
		'title'          => $post->post_title,
		'kind'           => 0 === strpos( $mime, 'video/' ) ? 'recording' : 'screenshot',
		'mime'           => $mime,
		'createdAt'      => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $post->post_date_gmt . ' UTC' ) ),
		'author'         => array(
			'id'   => (int) $post->post_author,
			'name' => $author ? $author->display_name : '',
		),
		'folderId'       => $folder,
		'tagIds'         => array_map( 'intval', $tags ),
		'width'          => isset( $meta['width'] ) ? (int) $meta['width'] : null,
		'height'         => isset( $meta['height'] ) ? (int) $meta['height'] : null,
		'sizeBytes'      => $bytes,
		'videopressGuid' => $guid,
		'mediaUrl'       => $local ? $base_url : null,
		'downloadUrl'    => $local ? add_query_arg( 'download', '1', $base_url ) : null,
		'posterUrl'      => $guid && pressshot_videopress_poster( $id ) ? rest_url( 'pressshot/v1/shots/' . $id . '/poster' ) : null,
		'canEdit'        => current_user_can( 'edit_post', $id ),
		'canDelete'      => current_user_can( 'delete_post', $id ),
		'trashed'        => pressshot_is_trashed( $id ),
		'link'           => $library && isset( $context['links'][ $id ] ) ? $context['links'][ $id ] : null,
		// A folder link also shares this shot, so revoking the shot's own link doesn't unshare it. Names only, never tokens.
		'folderLinked'   => null !== $sharing,
		'folderLinkName' => $sharing ? $sharing->name : null,
	);
}

/**
 * Loads what a page of DTOs needs in a few queries instead of several per shot: posts, meta,
 * terms, authors, every shot link, and the live folder links.
 *
 * @param int[] $ids Shot ids.
 * @return array{links: array, folder_links: array}
 */
function pressshot_shot_dto_context( array $ids ) {
	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
	if ( ! $ids ) {
		return array( 'links' => array(), 'folder_links' => array() );
	}
	_prime_post_caches( $ids, false, false );
	update_meta_cache( 'post', $ids );
	update_object_term_cache( $ids, 'attachment' );
	cache_users( array_map( 'intval', wp_list_pluck( array_filter( array_map( 'get_post', $ids ) ), 'post_author' ) ) );
	$library = pressshot_can_use_library();
	return array(
		'links'        => $library ? pressshot_links_for( 'shot', $ids ) : array(),
		'folder_links' => $library ? pressshot_live_folder_links() : array(),
	);
}

/** DTOs for a list of shot ids, sharing one set of lookups. */
function pressshot_shot_dtos( array $ids ) {
	$context = pressshot_shot_dto_context( $ids );
	return array_map(
		function ( $id ) use ( $context ) {
			return pressshot_shot_dto( $id, $context );
		},
		array_values( $ids )
	);
}

function pressshot_not_a_shot() {
	return new WP_Error( 'pressshot_not_found', __( 'Shot not found.', 'pressshot' ), array( 'status' => 404 ) );
}

function pressshot_list_shots( WP_REST_Request $request ) {
	$args = array(
		'post_type'      => 'attachment',
		'post_status'    => 'trash' === $request['status'] ? 'trash' : 'inherit',
		'posts_per_page' => (int) $request['perPage'],
		'paged'          => (int) $request['page'],
		'orderby'        => $request['orderby'],
		'order'          => strtoupper( $request['order'] ),
		'meta_query'     => array(
			array(
				'key'   => PRESSSHOT_SHOT_META,
				'value' => '1',
			),
		),
		'no_found_rows'  => false,
	);
	if ( '' !== (string) $request['search'] ) {
		$args['s'] = (string) $request['search'];
	}
	if ( $request['type'] ) {
		$args['post_mime_type'] = 'recording' === $request['type'] ? 'video' : 'image';
	}
	if ( null !== $request['author'] ) {
		$args['author'] = (int) $request['author'];
	}
	$tax = array();
	// A folder lists only the shots filed directly in it; its subfolders list their own.
	if ( null !== $request['folder'] ) {
		$tax[] = (int) $request['folder'] > 0
			? array( 'taxonomy' => 'pressshot_folder', 'field' => 'term_id', 'terms' => array( (int) $request['folder'] ), 'include_children' => false )
			: array( 'taxonomy' => 'pressshot_folder', 'operator' => 'NOT EXISTS' );
	}
	if ( $request['tag'] ) {
		$tax[] = array( 'taxonomy' => 'pressshot_tag', 'field' => 'term_id', 'terms' => array( (int) $request['tag'] ) );
	}
	if ( $tax ) {
		$args['tax_query'] = array_merge( array( 'relation' => 'AND' ), $tax );
	}
	$query = new WP_Query( $args );
	return rest_ensure_response(
		array(
			'items'      => pressshot_shot_dtos( wp_list_pluck( $query->posts, 'ID' ) ),
			'total'      => (int) $query->found_posts,
			'totalPages' => (int) $query->max_num_pages,
		)
	);
}

function pressshot_get_shot( WP_REST_Request $request ) {
	$id = (int) $request['id'];
	return pressshot_is_shot( $id ) ? rest_ensure_response( pressshot_shot_dto( $id ) ) : pressshot_not_a_shot();
}

/**
 * A VideoPress viewing pass for the Library viewer: { guid, pass }. pass is null when the site
 * can't mint one (no Jetpack), and the player then plays only if the video isn't private.
 */
function pressshot_get_videopress_pass( WP_REST_Request $request ) {
	$id   = (int) $request['id'];
	$guid = pressshot_is_shot( $id ) ? pressshot_videopress_guid( $id ) : null;
	if ( ! $guid ) {
		return pressshot_not_a_shot();
	}
	$response = rest_ensure_response( array( 'guid' => $guid, 'pass' => pressshot_videopress_pass( $guid ) ) );
	$response->header( 'Cache-Control', 'private, no-store' );
	return $response;
}

/**
 * Sends the browser to a recording's VideoPress still. The pass is minted here, per request, so the
 * DTO never carries one and an image tag can't outlive it; without Jetpack the still goes unsigned.
 */
function pressshot_get_shot_poster( WP_REST_Request $request ) {
	$id     = (int) $request['id'];
	$guid   = pressshot_is_shot( $id ) ? pressshot_videopress_guid( $id ) : null;
	$poster = $guid ? pressshot_videopress_poster( $id ) : null;
	if ( ! $poster ) {
		return pressshot_not_a_shot();
	}
	return pressshot_poster_redirect( $guid, $poster );
}

/** Trashed shots stream too, so the Trash view can show previews. */
function pressshot_get_shot_media( WP_REST_Request $request ) {
	$id = (int) $request['id'];
	if ( ! pressshot_is_shot( $id ) ) {
		return pressshot_not_a_shot();
	}
	return pressshot_stream_file( $id, $request, (string) $request['size'] );
}

/** Files shots into a folder and adds or removes tags. Checks everything first, then changes. */
function pressshot_organize_shots( WP_REST_Request $request ) {
	$ids  = array_values( array_unique( array_map( 'intval', (array) $request['ids'] ) ) );
	$bad  = new WP_Error( 'pressshot_bad_request', __( 'Some of those shots or folders no longer exist.', 'pressshot' ), array( 'status' => 400 ) );
	$add  = array_values( array_unique( array_map( 'intval', (array) $request['addTags'] ) ) );
	$drop = array_values( array_unique( array_map( 'intval', (array) $request['removeTags'] ) ) );
	foreach ( $ids as $id ) {
		if ( ! pressshot_is_shot( $id ) ) {
			return $bad;
		}
	}
	$folder = $request['folder'];
	if ( null !== $folder && (int) $folder > 0 && ! term_exists( (int) $folder, 'pressshot_folder' ) ) {
		return $bad;
	}
	foreach ( array_merge( $add, $drop ) as $term ) {
		if ( ! term_exists( $term, 'pressshot_tag' ) ) {
			return $bad;
		}
	}
	foreach ( $ids as $id ) {
		if ( null !== $folder ) {
			wp_set_object_terms( $id, (int) $folder > 0 ? array( (int) $folder ) : array(), 'pressshot_folder' );
		}
		if ( $add ) {
			wp_set_object_terms( $id, $add, 'pressshot_tag', true );
		}
		if ( $drop ) {
			wp_remove_object_terms( $id, $drop, 'pressshot_tag' );
		}
	}
	return rest_ensure_response( array( 'items' => pressshot_shot_dtos( $ids ) ) );
}

add_action(
	'rest_api_init',
	function () {
		$ns   = 'pressshot/v1';
		$perm = 'pressshot_library_permission';
		$int_ids = array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		);
		register_rest_route(
			$ns,
			'/shots',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'pressshot_list_shots',
				'permission_callback' => $perm,
				'args'                => array(
					'page'    => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'perPage' => array( 'type' => 'integer', 'default' => 40, 'minimum' => 1, 'maximum' => 100 ),
					'search'  => array( 'type' => 'string', 'default' => '' ),
					'type'    => array( 'type' => 'string', 'enum' => array( 'screenshot', 'recording' ) ),
					'author'  => array( 'type' => 'integer', 'minimum' => 1 ),
					'folder'  => array( 'type' => 'integer', 'minimum' => 0 ),
					'tag'     => array( 'type' => 'integer', 'minimum' => 1 ),
					'status'  => array( 'type' => 'string', 'enum' => array( 'active', 'trash' ), 'default' => 'active' ),
					'orderby' => array( 'type' => 'string', 'enum' => array( 'date', 'title' ), 'default' => 'date' ),
					'order'   => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/shots/organize',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'pressshot_organize_shots',
				'permission_callback' => $perm,
				'args'                => array(
					'ids'        => array_merge( $int_ids, array( 'required' => true, 'minItems' => 1, 'maxItems' => 100 ) ),
					'folder'     => array( 'type' => 'integer', 'minimum' => 0 ),
					'addTags'    => $int_ids,
					'removeTags' => $int_ids,
				),
			)
		);
		register_rest_route(
			$ns,
			'/shots/(?P<id>[1-9][0-9]*)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'pressshot_get_shot',
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$ns,
			'/shots/(?P<id>[1-9][0-9]*)/videopress-pass',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'pressshot_get_videopress_pass',
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$ns,
			'/shots/(?P<id>[1-9][0-9]*)/poster',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'pressshot_get_shot_poster',
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$ns,
			'/shots/(?P<id>[1-9][0-9]*)/media',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'pressshot_get_shot_media',
				'permission_callback' => $perm,
				'args'                => array(
					'download' => array( 'type' => 'string' ),
					'size'     => array( 'type' => 'string', 'enum' => array( 'full', 'medium' ), 'default' => 'full' ),
				),
			)
		);
	}
);
