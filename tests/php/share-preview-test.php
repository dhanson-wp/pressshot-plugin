<?php
/**
 * Integration checks for share link previews: the Open Graph and Twitter card tags on /s/{token}.
 * Run with bin/test-php.sh. It creates and removes only its own users, shots, folder terms, and link records.
 */
require __DIR__ . '/harness.php';

$GLOBALS['pressshot_test_terms'] = array();
register_shutdown_function(
	function () {
		unset( $_GET['shot'] );
		set_query_var( 'pressshot_share', '' );
		$GLOBALS['wp']->matched_rule = '';
		foreach ( $GLOBALS['pressshot_test_made']['attachments'] as $id ) {
			pressshot_link_revoke( 'shot', $id );
		}
		foreach ( $GLOBALS['pressshot_test_terms'] as $term_id ) {
			pressshot_link_revoke( 'folder', $term_id );
			wp_delete_term( $term_id, 'pressshot_folder' );
		}
	}
);

$tag      = wp_generate_password( 5, false );
$uploader = $make_user( 'author' );
$editor   = $make_user( 'editor' );
$site     = get_bloginfo( 'name', 'display' );

/** Pretend the current request is /s/{token}, optionally with ?shot=. */
$as_share = function ( $token, $shot = null ) {
	$GLOBALS['wp']->matched_rule = pressshot_share_rule();
	set_query_var( 'pressshot_share', $token );
	unset( $_GET['shot'] );
	if ( null !== $shot ) {
		$_GET['shot'] = (string) $shot;
	}
};
$not_share = function () {
	$GLOBALS['wp']->matched_rule = '';
	set_query_var( 'pressshot_share', '' );
	unset( $_GET['shot'] );
};
/** The content of one tag, or null when it isn't there. */
$tag_of = function ( $key ) {
	foreach ( pressshot_share_preview_tags() as $t ) {
		if ( $key === $t['key'] ) {
			return $t['content'];
		}
	}
	return null;
};
$head = function () {
	ob_start();
	do_action( 'wp_head' );
	return ob_get_clean();
};

// A screenshot link.
$shot = $make_shot( $uploader );
wp_update_post( array( 'ID' => $shot, 'post_title' => 'Chart "Q3" <draft> ' . $tag ) );
$link   = pressshot_link_create( 'shot', $shot );
$public = pressshot_public_shot( $shot, $link['token'] );
$as_share( $link['token'] );
$check( 'screenshot link: og:image is the large media URL', add_query_arg( 'size', 'large', $public['mediaUrl'] ) === $tag_of( 'og:image' ) );
$check( 'screenshot link: og:title is the shot title', 'Chart "Q3" <draft> ' . $tag === $tag_of( 'og:title' ) );
$check( 'screenshot link: og:description names the site', 'Shared from ' . $site === $tag_of( 'og:description' ) );
$check( 'screenshot link: og:url is the share link', pressshot_link_url( $link['token'] ) === $tag_of( 'og:url' ) );
$check( 'screenshot link: og:site_name and og:type', $site === $tag_of( 'og:site_name' ) && 'website' === $tag_of( 'og:type' ) );
$check( 'screenshot link: og:image:alt is the title', 'Chart "Q3" <draft> ' . $tag === $tag_of( 'og:image:alt' ) );
$check( 'screenshot link: twitter:card is summary_large_image', 'summary_large_image' === $tag_of( 'twitter:card' ) );
$check( 'screenshot link: twitter:card uses the name attribute', in_array( array( 'attr' => 'name', 'key' => 'twitter:card', 'content' => 'summary_large_image' ), pressshot_share_preview_tags(), true ) );
$check( 'screenshot link without size metadata: no width or height', null === $tag_of( 'og:image:width' ) && null === $tag_of( 'og:image:height' ) );

$html = $head();
$check( 'title with quotes is escaped in wp_head output', false !== strpos( $html, 'content="Chart &quot;Q3&quot; &lt;draft&gt; ' . $tag . '"' ) && false === strpos( $html, '<draft>' ) );
$check( 'wp_head prints og:image as a property meta tag', false !== strpos( $html, '<meta property="og:image" content="' . esc_attr( $tag_of( 'og:image' ) ) . '" />' ) );

wp_set_current_user( $editor );
$check( 'signed in: og:image has no _wpnonce', false === strpos( (string) $tag_of( 'og:image' ), '_wpnonce' ) );
wp_set_current_user( 0 );

// Width and height come from the size the preview serves.
$big_path = wp_upload_dir()['path'] . '/pstest-preview-' . $tag . '.png';
imagepng( imagecreatetruecolor( 2000, 1200 ), $big_path );
$big = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Big', 'post_status' => 'inherit', 'post_author' => $uploader ), $big_path );
update_post_meta( $big, '_pressshot_capture', 1 );
$GLOBALS['pressshot_test_made']['attachments'][] = $big;
wp_update_attachment_metadata( $big, wp_generate_attachment_metadata( $big, $big_path ) );
$big_link = pressshot_link_create( 'shot', $big );
$as_share( $big_link['token'] );
$check( 'screenshot link: og:image width and height come from the large size', '1536' === $tag_of( 'og:image:width' ) && '922' === $tag_of( 'og:image:height' ) );
$meta = wp_get_attachment_metadata( $big );
unset( $meta['sizes'] );
wp_update_attachment_metadata( $big, $meta );
$check( 'screenshot link without intermediates: width and height are the original', '2000' === $tag_of( 'og:image:width' ) && '1200' === $tag_of( 'og:image:height' ) );

// Recordings.
$vp = $make_shot( $uploader, 'video/mp4' );
update_post_meta( $vp, 'videopress_guid', 'GuIdPrv1' );
wp_update_attachment_metadata( $vp, array( 'videopress' => array( 'poster' => 'https://videos.files.wordpress.com/GuIdPrv1/clip.original.jpg' ) ) );
$vp_link = pressshot_link_create( 'shot', $vp );
$as_share( $vp_link['token'] );
$check( 'recording with poster: og:image is the poster route', pressshot_public_shot( $vp, $vp_link['token'] )['posterUrl'] === $tag_of( 'og:image' ) && null !== $tag_of( 'og:image' ) );
$check( 'recording with poster: twitter:card is summary_large_image', 'summary_large_image' === $tag_of( 'twitter:card' ) );

$mp4      = $make_shot( $uploader, 'video/mp4' );
$mp4_link = pressshot_link_create( 'shot', $mp4 );
$as_share( $mp4_link['token'] );
$check( 'local recording without poster: no og:image, twitter:card summary', null === $tag_of( 'og:image' ) && 'summary' === $tag_of( 'twitter:card' ) && 'Test shot' === $tag_of( 'og:title' ) );

// Folders.
$term = wp_insert_term( 'Preview folder ' . $tag, 'pressshot_folder' );
$GLOBALS['pressshot_test_terms'][] = (int) $term['term_id'];
wp_set_object_terms( $shot, array( (int) $term['term_id'] ), 'pressshot_folder' );
$flink = pressshot_link_create( 'folder', (int) $term['term_id'] );
$as_share( $flink['token'] );
$check( 'folder link: folder name, no og:image', 'Preview folder ' . $tag === $tag_of( 'og:title' ) && null === $tag_of( 'og:image' ) && 'summary' === $tag_of( 'twitter:card' ) );
$check( 'folder link: og:url is the folder link', pressshot_link_url( $flink['token'] ) === $tag_of( 'og:url' ) );
$as_share( $flink['token'], $shot );
$check( 'folder link with covered ?shot=: that shot\'s tags', 'Chart "Q3" <draft> ' . $tag === $tag_of( 'og:title' ) && add_query_arg( 'size', 'large', pressshot_public_shot( $shot, $flink['token'] )['mediaUrl'] ) === $tag_of( 'og:image' ) );
$check( 'folder link with covered ?shot=: og:url keeps the shot', add_query_arg( 'shot', $shot, pressshot_link_url( $flink['token'] ) ) === $tag_of( 'og:url' ) );
$as_share( $flink['token'], $mp4 );
$check( 'folder link with uncovered ?shot=: folder tags', 'Preview folder ' . $tag === $tag_of( 'og:title' ) && null === $tag_of( 'og:image' ) );

// Dead links and other pages.
pressshot_link_revoke( 'shot', $shot );
$as_share( $link['token'] );
$check( 'revoked link: no tags', array() === pressshot_share_preview_tags() && false === strpos( $head(), 'og:' ) );
$as_share( str_repeat( 'a', 32 ) );
$check( 'unknown token: no tags', array() === pressshot_share_preview_tags() );
$not_share();
set_query_var( 'pressshot_share', $big_link['token'] );
$check( 'not a share request: no tags', array() === pressshot_share_preview_tags() );

// Jetpack.
$as_share( $big_link['token'] );
$check( 'Jetpack Open Graph is off on share requests', false === apply_filters( 'jetpack_enable_open_graph', true ) );
$not_share();
$check( 'Jetpack Open Graph is untouched elsewhere', true === apply_filters( 'jetpack_enable_open_graph', true ) );
