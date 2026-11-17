<?php
/**
 * Folders and tags for a PressShot Library.
 *
 * Both are ordinary WordPress taxonomies on media, so they live in the site's own database, show up
 * beside each item in the Media Library, and travel with an export. The Library reads and changes
 * them through WordPress's standard REST routes (/wp/v2/pressshot-folders, /wp/v2/pressshot-tags, and
 * the same-named fields on /wp/v2/media). Nothing here is tied to one person: anyone who can upload
 * to the site can create, rename, and remove them, and file any shot. The exception is a folder a
 * live share link covers: filing a shot there, or moving a folder under it, shares it, so that takes
 * the permission the link itself takes (see pressshot_check_shot_exposure() in links.php).
 *
 * Folders nest (a folder can have a parent); tags stay flat. Deleting a folder, with the choice of
 * moving its contents or removing everything in it, is in folders.php.
 *
 * A shot sits in one folder at most. The Library sends one; this file keeps it to one if anything
 * else sends more.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	function () {
		$shared = array(
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_nav_menus'  => false,
			'show_tagcloud'      => false,
			'show_admin_column'  => true,
			'show_in_rest'       => true,
			'hierarchical'       => false,
			'rewrite'            => false,
			'query_var'          => false,
			// Media that isn't attached to a post is skipped by the default counter.
			'update_count_callback' => '_update_generic_term_count',
			'capabilities'       => array(
				'manage_terms' => 'upload_files',
				'edit_terms'   => 'upload_files',
				'delete_terms' => 'upload_files',
				'assign_terms' => 'upload_files',
			),
		);
		register_taxonomy(
			'pressshot_folder',
			'attachment',
			array_merge(
				$shared,
				array(
					'hierarchical' => true,
					'labels'    => array(
						'name'          => __( 'PressShot folders', 'pressshot' ),
						'singular_name' => __( 'PressShot folder', 'pressshot' ),
					),
					'rest_base' => 'pressshot-folders',
				)
			)
		);
		register_taxonomy(
			'pressshot_tag',
			'attachment',
			array_merge(
				$shared,
				array(
					'labels'    => array(
						'name'          => __( 'PressShot tags', 'pressshot' ),
						'singular_name' => __( 'PressShot tag', 'pressshot' ),
					),
					'rest_base' => 'pressshot-tags',
				)
			)
		);
	}
);

/** One folder per shot: if several are set at once, keep the last one given. */
add_action(
	'set_object_terms',
	function ( $object_id, $terms, $tt_ids, $taxonomy ) {
		static $fixing = false;
		if ( 'pressshot_folder' !== $taxonomy || $fixing || count( $tt_ids ) < 2 ) {
			return;
		}
		$fixing = true;
		$kept   = get_term_by( 'term_taxonomy_id', (int) end( $tt_ids ), 'pressshot_folder' );
		wp_set_object_terms( $object_id, $kept ? array( (int) $kept->term_id ) : array(), 'pressshot_folder' );
		$fixing = false;
	},
	10,
	4
);

/**
 * How many shots each folder and tag holds, not counting Trash: term_id => count, for both
 * taxonomies, from one query. Core's term count can't be used: it counts any media in the term,
 * trashed shots included. A folder counts only the shots filed directly in it, as its list does.
 *
 * Cached against the posts and terms last-changed keys, so filing, trashing, restoring, or
 * deleting a shot gives a fresh count.
 *
 * @return array<int, int> Counts by term id. Terms with no shots aren't listed.
 */
function pressshot_term_shot_counts() {
	global $wpdb;
	$key    = 'term_shot_counts:' . wp_cache_get_last_changed( 'posts' ) . ':' . wp_cache_get_last_changed( 'terms' );
	$counts = wp_cache_get( $key, 'pressshot' );
	if ( is_array( $counts ) ) {
		return $counts;
	}
	$rows   = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT tt.term_id, COUNT( DISTINCT p.ID ) AS shots
			FROM {$wpdb->term_taxonomy} tt
			INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'attachment' AND p.post_status = 'inherit'
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = '1'
			WHERE tt.taxonomy IN ( 'pressshot_folder', 'pressshot_tag' )
			GROUP BY tt.term_id",
			PRESSSHOT_SHOT_META
		)
	);
	$counts = array();
	foreach ( $rows as $row ) {
		$counts[ (int) $row->term_id ] = (int) $row->shots;
	}
	wp_cache_set( $key, $counts, 'pressshot' );
	return $counts;
}

/** shotCount on folders and tags in the REST API, for the Library sidebar. Library users only. */
add_action(
	'rest_api_init',
	function () {
		foreach ( array( 'pressshot_folder', 'pressshot_tag' ) as $taxonomy ) {
			register_rest_field(
				$taxonomy,
				'shotCount',
				array(
					'get_callback' => function ( $term ) {
						if ( ! pressshot_can_use_library() ) {
							return null;
						}
						$counts = pressshot_term_shot_counts();
						return $counts[ (int) $term['id'] ] ?? 0;
					},
					'schema'       => array(
						'description' => __( 'Shots in this folder or tag, not counting Trash.', 'pressshot' ),
						'type'        => array( 'integer', 'null' ),
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
				)
			);
		}
	}
);
