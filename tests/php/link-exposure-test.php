<?php
/**
 * Filing a shot into a folder a live link covers, or moving a folder under one, shares it. Those
 * moves take the permission the link itself takes. Run with bin/test-php.sh. It creates and
 * removes only its own users, folders, links, and attachments.
 */
require __DIR__ . '/harness.php';

$author_a = $make_user( 'author' );
$author_b = $make_user( 'author' );
$editor   = $make_user( 'editor' );
$reader   = $make_user( 'subscriber' );
$suffix   = wp_generate_password( 6, false );
$made     = array();
$folder   = function ( $name, $parent = 0 ) use ( $suffix, &$made ) {
	$term   = wp_insert_term( $name . ' ' . $suffix, 'pressshot_folder', array( 'parent' => $parent ) );
	$made[] = (int) $term['term_id'];
	return (int) $term['term_id'];
};
$folder_of = function ( $shot ) {
	$terms = wp_get_object_terms( $shot, 'pressshot_folder', array( 'fields' => 'ids' ) );
	return $terms ? (int) $terms[0] : 0;
};
$parent_of = function ( $term_id ) {
	clean_term_cache( $term_id, 'pressshot_folder' );
	return (int) get_term( $term_id, 'pressshot_folder' )->parent;
};
$organize = function ( $ids, $to ) use ( $call ) {
	return $call( 'POST', '/pressshot/v1/shots/organize', array( 'ids' => $ids, 'folder' => $to ) );
};
$code = function ( $response ) {
	return $response->get_data()['code'] ?? '';
};

$shared  = $folder( 'Shared' );
$sub     = $folder( 'Shared sub', $shared );
$plain   = $folder( 'Plain' );
$expired = $folder( 'Expired' );
pressshot_link_create( 'folder', $shared );
$old = pressshot_link_create( 'folder', $expired );
update_post_meta( $old['id'], '_pressshot_link_expires', time() - 60 );

$mine   = $make_shot( $author_a );
$theirs = $make_shot( $author_b );

// Shots.
wp_set_current_user( $author_a );
$refused = $organize( array( $theirs ), $shared );
$check( 'an author can\'t file someone else\'s shot into a shared folder (403)', 403 === $refused->get_status() && 'pressshot_link_exposure' === $code( $refused ) );
$check( 'the refusal names the shared folder', false !== strpos( $refused->get_data()['message'], 'Shared ' . $suffix ) && $shared === ( $refused->get_data()['data']['folderId'] ?? 0 ) );
$check( 'the refused shot stays where it was', 0 === $folder_of( $theirs ) );
$check( 'a subfolder of a shared folder is covered too', 'pressshot_link_exposure' === $code( $organize( array( $theirs ), $sub ) ) && 0 === $folder_of( $theirs ) );

$batch = $organize( array( $mine, $theirs ), $shared );
$check( 'a batch with one shot the author can\'t share is refused whole', 403 === $batch->get_status() && 1 === ( $batch->get_data()['data']['count'] ?? 0 ) && 0 === $folder_of( $mine ) && 0 === $folder_of( $theirs ) );

$check( 'an author files their own shot into a shared folder', 200 === $organize( array( $mine ), $shared )->get_status() && $shared === $folder_of( $mine ) );
$check( 'an author files someone else\'s shot into an unshared folder', 200 === $organize( array( $theirs ), $plain )->get_status() && $plain === $folder_of( $theirs ) );
$check( 'an expired link doesn\'t count', 200 === $organize( array( $theirs ), $expired )->get_status() && $expired === $folder_of( $theirs ) );

$tags = $call( 'POST', '/pressshot/v1/shots/organize', array( 'ids' => array( $theirs ), 'addTags' => array() ) );
$check( 'a tags-only change isn\'t checked', 200 === $tags->get_status() );

wp_set_current_user( $editor );
$check( 'an editor files someone else\'s shot into a shared folder', 200 === $organize( array( $theirs ), $shared )->get_status() && $shared === $folder_of( $theirs ) );

wp_set_current_user( $author_a );
$check( 'moving a shot within the same shared folder\'s tree is allowed', 200 === $organize( array( $theirs ), $sub )->get_status() && $sub === $folder_of( $theirs ) );
$check( 'taking a shot out of a shared folder is allowed', 200 === $organize( array( $theirs ), 0 )->get_status() && 0 === $folder_of( $theirs ) );

// Folders, through WordPress's folder route.
$base  = '/wp/v2/pressshot-folders/';
$moved = $call( 'POST', $base . $plain, array( 'parent' => $shared ) );
$check( 'an author can\'t move a folder into a shared folder (403)', 403 === $moved->get_status() && 'pressshot_link_exposure' === $code( $moved ) && 0 === $parent_of( $plain ) );
$check( 'nor into a shared folder\'s subfolder', 'pressshot_link_exposure' === $code( $call( 'POST', $base . $plain, array( 'parent' => $sub ) ) ) && 0 === $parent_of( $plain ) );
$check( 'an author can still rename a folder inside a shared one', 200 === $call( 'POST', $base . $sub, array( 'name' => 'Renamed ' . $suffix ) )->get_status() );
$check( 'an author can move a folder into an unshared folder', 200 === $call( 'POST', $base . $plain, array( 'parent' => $expired ) )->get_status() && $expired === $parent_of( $plain ) );

wp_set_current_user( $reader );
$check( 'someone without Library access gets WordPress\'s own refusal, not the shared folder\'s name', 403 === ( $r = $call( 'POST', $base . $plain, array( 'parent' => $shared ) ) )->get_status() && 'pressshot_link_exposure' !== $code( $r ) );

wp_set_current_user( $editor );
$check( 'an editor moves a folder into a shared folder', 200 === $call( 'POST', $base . $plain, array( 'parent' => $shared ) )->get_status() && $shared === $parent_of( $plain ) );

wp_set_current_user( $author_a );
$check( 'an author moves a folder within the same shared tree', 200 === $call( 'POST', $base . $plain, array( 'parent' => $sub ) )->get_status() && $sub === $parent_of( $plain ) );
$check( 'an author moves a folder out of a shared tree', 200 === $call( 'POST', $base . $plain, array( 'parent' => 0 ) )->get_status() && 0 === $parent_of( $plain ) );

// Anything else that reparents a folder (the admin screen, Quick Edit) keeps the old parent.
wp_update_term( $plain, 'pressshot_folder', array( 'parent' => $shared ) );
$check( 'wp_update_term by an author keeps the old parent', 0 === $parent_of( $plain ) );
wp_set_current_user( 0 );
wp_update_term( $plain, 'pressshot_folder', array( 'parent' => $shared ) );
$check( 'WP-CLI and cron (nobody signed in) aren\'t checked', $shared === $parent_of( $plain ) );
wp_update_term( $plain, 'pressshot_folder', array( 'parent' => 0 ) );

// Deleting a folder and moving what's in it into a shared folder.
$doomed = $folder( 'Doomed' );
$inner  = $folder( 'Doomed inner', $doomed );
wp_set_object_terms( $theirs, array( $doomed ), 'pressshot_folder' );
$delete = function ( $id, $target ) use ( $call ) {
	return $call( 'POST', "/pressshot/v1/folders/$id/delete", array( 'mode' => 'move', 'target' => $target, 'confirm' => 'DELETE' ) );
};
wp_set_current_user( $author_a );
$gone = $delete( $doomed, $shared );
$check( 'an author can\'t delete a folder into a shared one when it holds a subfolder', 403 === $gone->get_status() && 'pressshot_link_exposure' === $code( $gone ) );
$check( 'nothing changed: the folder, its subfolder, and the shot stay put', get_term( $doomed, 'pressshot_folder' ) instanceof WP_Term && $doomed === $parent_of( $inner ) && $doomed === $folder_of( $theirs ) );

wp_delete_term( $inner, 'pressshot_folder' );
$gone = $delete( $doomed, $shared );
$check( 'nor when it holds someone else\'s shot', 403 === $gone->get_status() && 'pressshot_link_exposure' === $code( $gone ) && $doomed === $folder_of( $theirs ) );

wp_set_current_user( $editor );
$gone = $delete( $doomed, $shared );
$check( 'an editor deletes a folder into a shared one', 200 === $gone->get_status() && $shared === $folder_of( $theirs ) );

// Cleanup: deleting the folders revokes their links.
wp_set_current_user( 1 );
foreach ( array_reverse( $made ) as $term_id ) {
	if ( get_term( $term_id, 'pressshot_folder' ) instanceof WP_Term ) {
		wp_delete_term( $term_id, 'pressshot_folder' );
	}
}
$check( 'cleanup leaves no link behind', null === pressshot_link_for( 'folder', $shared ) && null === pressshot_link_for( 'folder', $expired ) );
