<?php
/**
 * Integration checks for the public share pages (/s/{token}) and the Task 5 follow-ups in links.php.
 * Run with bin/test-php.sh. It creates and removes only its own users, shots, folder terms, and link records.
 */
require __DIR__ . '/harness.php';

$GLOBALS['pressshot_test_terms'] = array();
register_shutdown_function(
	function () {
		unset( $_GET['shot'] );
		foreach ( $GLOBALS['pressshot_test_made']['attachments'] as $id ) {
			pressshot_link_revoke( 'shot', $id );
		}
		foreach ( $GLOBALS['pressshot_test_terms'] as $term_id ) {
			pressshot_link_revoke( 'folder', $term_id );
			if ( term_exists( $term_id, 'pressshot_folder' ) ) {
				wp_delete_term( $term_id, 'pressshot_folder' );
			}
		}
	}
);

$tag      = wp_generate_password( 5, false );
$uploader = $make_user( 'author' );
wp_update_user( array( 'ID' => $uploader, 'display_name' => 'Uploader' . $tag ) );
$editor   = $make_user( 'editor' );
$folder   = function ( $name, $parent = 0 ) use ( $call, $tag ) {
	$response = $call( 'POST', '/wp/v2/pressshot-folders', array( 'name' => $name . $tag, 'parent' => $parent ) );
	if ( 201 !== $response->get_status() ) {
		throw new RuntimeException( 'Could not create folder: ' . wp_json_encode( $response->get_data() ) );
	}
	$GLOBALS['pressshot_test_terms'][] = $response->get_data()['id'];
	return $response->get_data()['id'];
};
$title    = function ( $id, $text ) {
	wp_update_post( array( 'ID' => $id, 'post_title' => $text ) );
};
$emit     = function ( $response ) {
	ob_start();
	$response->emit( 'GET' );
	return ob_get_clean();
};
$png      = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' );
$statuses = array();
add_filter(
	'status_header',
	function ( $header, $code ) use ( &$statuses ) {
		$statuses[] = $code;
		return $header;
	},
	10,
	2
);
$render   = function ( $token, $shot = null, $markup = '<!-- wp:pressshot/share-view /-->' ) use ( &$statuses ) {
	$statuses = array();
	set_query_var( 'pressshot_share', $token );
	unset( $_GET['shot'] );
	if ( null !== $shot ) {
		$_GET['shot'] = (string) $shot;
	}
	return do_blocks( $markup );
};
// The text of the top bar: everything from its opening tag up to the media stage.
$bar      = function ( $html ) {
	return preg_match( '#<div class="pressshot-share__bar">(.*?)<div class="pressshot-share__(?:stage|body)"#s', $html, $m ) ? $m[1] : '';
};
$gone     = 'This link is no longer available.';

// Links.php follow-ups: only published link records resolve.
$shot = $make_shot( $uploader );
$title( $shot, 'Quarterly chart ' . $tag );
$link = pressshot_link_create( 'shot', $shot );
$token = $link['token'];
$check( 'a published link resolves', null !== pressshot_link_resolve( $token ) );
wp_update_post( array( 'ID' => $link['id'], 'post_status' => 'draft' ) );
$check( 'a draft link record does not resolve', null === pressshot_link_resolve( $token ) );
wp_update_post( array( 'ID' => $link['id'], 'post_status' => 'trash' ) );
$check( 'a trashed link record does not resolve', null === pressshot_link_resolve( $token ) );
wp_update_post( array( 'ID' => $link['id'], 'post_status' => 'publish' ) );
$check( 'republishing it resolves again', null !== pressshot_link_resolve( $token ) );

// Anonymous media route: lenient size, identical 404, private headers.
wp_set_current_user( 0 );
$media = "/pressshot/v1/links/$token/shots/$shot/media";
$bogus = $call( 'GET', $media, array( 'size' => 'bogus' ) );
$check( 'size=bogus with a valid token streams the full file', 200 === $bogus->get_status() && $emit( $bogus ) === $png );
$check( 'download as an array is not rejected by the router', 200 === $call( 'GET', $media, array( 'download' => array( '1' ) ) )->get_status() );
$random   = wp_generate_password( 32, false );
$unknown  = $call( 'GET', "/pressshot/v1/links/$random" );
$bad_size = $call( 'GET', "/pressshot/v1/links/$random/shots/$shot/media", array( 'size' => 'bogus' ) );
$check( 'size=bogus with an invalid token is the identical 404', 404 === $bad_size->get_status() && wp_json_encode( $bad_size->get_data() ) === wp_json_encode( $unknown->get_data() ) );
$view = $call( 'GET', "/pressshot/v1/links/$token" );
$check( 'link JSON success is private and noindex', 'private, no-store' === $view->get_headers()['Cache-Control'] && 'noindex' === $view->get_headers()['X-Robots-Tag'] );
$check( 'link JSON failure is private and noindex', 'private, no-store' === $unknown->get_headers()['Cache-Control'] && 'noindex' === $unknown->get_headers()['X-Robots-Tag'] );
$check( 'media failure is private and noindex', 'private, no-store' === $bad_size->get_headers()['Cache-Control'] && 'noindex' === $bad_size->get_headers()['X-Robots-Tag'] );
$check( 'media success is no-store and noindex', false !== strpos( $bogus->get_headers()['Cache-Control'], 'no-store' ) && 'noindex' === $bogus->get_headers()['X-Robots-Tag'] );

// Streaming is bounded: a slow client can't hold a PHP worker for a shared image.
$check( 'a shared image streams with the image time cap', $bogus instanceof PressShot_Media_Response && PRESSSHOT_LINK_IMAGE_TIME_LIMIT === $bogus->time_limit() && PRESSSHOT_LINK_IMAGE_TIME_LIMIT > 0 );
$recording = $make_shot( $uploader, 'video/mp4' );
$rlink     = pressshot_link_create( 'shot', $recording );
$rmedia    = $call( 'GET', "/pressshot/v1/links/{$rlink['token']}/shots/$recording/media" );
$check( 'a shared recording stays uncapped', $rmedia instanceof PressShot_Media_Response && 0 === $rmedia->time_limit() );
$cap = function ( $seconds, $mime ) {
	return 'video/mp4' === $mime ? 600 : 5;
};
add_filter( 'pressshot_link_media_time_limit', $cap, 10, 2 );
$check( 'the cap can be changed per type with a filter', 5 === $call( 'GET', $media )->time_limit() && 600 === $call( 'GET', "/pressshot/v1/links/{$rlink['token']}/shots/$recording/media" )->time_limit() );
remove_filter( 'pressshot_link_media_time_limit', $cap, 10 );
pressshot_link_revoke( 'shot', $recording );
wp_set_current_user( $uploader );
$check( 'the signed-in Library route keeps its uncapped stream', 0 === $call( 'GET', "/pressshot/v1/shots/$shot/media" )->time_limit() );
wp_set_current_user( 0 );

if ( ! class_exists( 'PressShot_Test_Clock_Response' ) ) {
	/** A media response whose clock moves one second every time emit() looks at it. */
	class PressShot_Test_Clock_Response extends PressShot_Media_Response {
		public $ticks = 0;
		protected function now() {
			return (float) $this->ticks++;
		}
	}
}
$big = fopen( 'php://temp', 'w+b' );
fwrite( $big, str_repeat( 'x', 65536 * 10 ) );
$slow = new PressShot_Test_Clock_Response( $big, 'image/png', 65536 * 10, 0, 65536 * 10 - 1, false, false );
$slow->limit_time( 3 );
$check( 'a capped stream stops at the deadline', 65536 * 2 === strlen( $emit( $slow ) ) );
$all = fopen( 'php://temp', 'w+b' );
fwrite( $all, str_repeat( 'x', 65536 * 10 ) );
$full = new PressShot_Test_Clock_Response( $all, 'image/png', 65536 * 10, 0, 65536 * 10 - 1, false, false );
$check( 'an uncapped stream sends everything', 65536 * 10 === strlen( $emit( $full ) ) );

// Shot share page.
$html = $render( $token );
$check( 'shot page shows the title', false !== strpos( $html, 'Quarterly chart ' . $tag ) );
$check( 'shot page image uses the token media URL', 1 === preg_match( '#<img src="[^"]*' . preg_quote( "/links/$token/shots/$shot/media", '#' ) . '"#', $html ) );
$check( 'shot page has a Download button on the token download URL', 1 === preg_match( '#<a [^>]*href="[^"]*' . $token . '[^"]*download=1[^"]*"[^>]*>Download</a>#', $html ) );
$check( 'shot page has a Copy link button', false !== strpos( $html, 'data-wp-on--click="actions.copy"' ) && false !== strpos( $html, 'Copy link' ) );
$check( 'shot page carries the page URL for copying', 1 === preg_match( '#data-wp-context=\'\{[^\']*' . preg_quote( str_replace( '/', '\\/', pressshot_link_url( $token ) ), '#' ) . '#', $html ) );
$check( 'shot page is not a 404', ! in_array( 404, $statuses, true ) );
$check( 'shot page hides the uploader', false === strpos( $html, 'Uploader' . $tag ) && false === stripos( $html, 'pstest_author' ) );
$check( 'shot page is the interactive wrapper', false !== strpos( $html, 'data-wp-interactive="pressshot/share-view"' ) );
$top = $bar( $html );
$check( 'shot page: top bar', '' !== $top
	&& 1 === preg_match( '#<h1 class="pressshot-share__name"[^>]*>Quarterly chart ' . $tag . '</h1>#', $top )
	&& false !== strpos( $top, '>Download</a>' )
	&& false !== strpos( $top, 'data-wp-on--click="actions.copy"' )
	&& false === strpos( $html, 'pressshot-share__title' )
	&& 1 === substr_count( $html, '<h1' ) );
$check( 'shot page: the image sits below the bar on the stage', 1 === preg_match( '#pressshot-share__stage.*<img src=#s', $html ) && false === strpos( $top, '<img' ) );
$check( 'shot page: the name carries a tooltip for long names', 1 === preg_match( '#<h1 class="pressshot-share__name" title="Quarterly chart ' . $tag . '">#', $top ) );
$site = get_bloginfo( 'name' );
$check( 'site name only when asked', false === strpos( $top, 'pressshot-share__site' ) );
$html = $render( $token, null, '<!-- wp:pressshot/share-view {"showSiteName":true} /-->' );
$check( 'site name only when asked: on, it links home', 1 === preg_match( '#<a class="pressshot-share__site" href="' . preg_quote( esc_url( home_url( '/' ) ), '#' ) . '">.*<span class="pressshot-share__site-name">' . preg_quote( esc_html( $site ), '#' ) . '</span></a>#s', $bar( $html ) ) );
// Without a site icon the bar shows the inline PressShot mark, hidden from screen readers.
$site_link = function ( $html ) use ( $bar ) {
	return preg_match( '#<a class="pressshot-share__site".*?</a>#s', $bar( $html ), $m ) ? $m[0] : '';
};
$link = $site_link( $html );
$check( 'site logo with the site name: no icon, the PressShot mark', false !== strpos( $link, '<svg' ) && false !== strpos( $link, 'aria-hidden="true"' ) && false !== strpos( $link, 'fill="currentColor"' ) && false === strpos( $link, '<img' ) );
$icon_filter = function () {
	return 'https://example.test/icon-64.png';
};
add_filter( 'get_site_icon_url', $icon_filter );
$html = $render( $token, null, '<!-- wp:pressshot/share-view {"showSiteName":true} /-->' );
$link = $site_link( $html );
$check( 'site logo with the site name: site icon as an empty-alt img', 1 === preg_match( '#<img [^>]*src="' . preg_quote( esc_url( get_site_icon_url( 64 ) ), '#' ) . '"[^>]*alt=""#', $link ) && false === strpos( $link, '<svg' ) );
$html = $render( $token );
$check( 'no logo without the site name', false === strpos( $bar( $html ), 'pressshot-share__site' ) && false === strpos( $bar( $html ), 'icon-64.png' ) && false === strpos( $bar( $html ), '<svg' ) );
remove_filter( 'get_site_icon_url', $icon_filter );
$html = $render( $token, null, '<!-- wp:pressshot/share-view --><!-- wp:paragraph --><p>Extra' . $tag . '</p><!-- /wp:paragraph --><!-- /wp:pressshot/share-view -->' );
$top  = $bar( $html );
$check( 'inner blocks render at the end of the bar', 1 === preg_match( '#actions\.copy.*<div class="pressshot-share__extras"><p[^>]*>Extra' . $tag . '</p>\s*</div>\s*</div>\s*$#s', $top ) );

// Video and VideoPress.
$vid = $make_shot( $uploader, 'video/mp4' );
$title( $vid, 'Walkthrough ' . $tag );
$vlink = pressshot_link_create( 'shot', $vid );
$html  = $render( $vlink['token'] );
$check( 'local video renders a video element with preload=metadata and a Download button', 1 === preg_match( '#<video controls[^>]*preload="metadata"[^>]*src="[^"]*' . $vlink['token'] . '#', $html ) && false !== strpos( $html, '>Download</a>' ) );
$vp = $make_shot( $uploader, 'video/mp4' );
update_post_meta( $vp, 'videopress_guid', 'AbCdEf12' );
$plink = pressshot_link_create( 'shot', $vp );
$html  = $render( $plink['token'] );
$check( 'VideoPress shot renders the VideoPress iframe', false !== strpos( $html, '<iframe src="https://videopress.com/embed/AbCdEf12"' ) );
$check( 'VideoPress shot has no Download and no local video', false === strpos( $html, 'Download' ) && false === strpos( $html, '<video' ) && false === strpos( $html, '/media' ) );

// Folder share page.
wp_set_current_user( $editor );
$f_top   = $folder( 'Top' );
$f_sub   = $folder( 'Sub', $f_top );
$f_other = $folder( 'Elsewhere' );
$in_top  = $make_shot( $uploader );
$in_sub  = $make_shot( $uploader );
$outside = $make_shot( $uploader );
$title( $in_top, 'Top shot ' . $tag );
$title( $in_sub, 'Sub shot ' . $tag );
$title( $outside, 'Outside shot ' . $tag );
wp_set_object_terms( $in_top, array( $f_top ), 'pressshot_folder' );
wp_set_object_terms( $in_sub, array( $f_sub ), 'pressshot_folder' );
wp_set_object_terms( $outside, array( $f_other ), 'pressshot_folder' );
$flink = pressshot_link_create( 'folder', $f_top );
wp_set_current_user( 0 );
$html = $render( $flink['token'] );
$check( 'folder page: bar shows the folder name', 1 === preg_match( '#<h1 class="pressshot-share__name"[^>]*>Top' . $tag . '</h1>#', $bar( $html ) ) && false !== strpos( $bar( $html ), 'actions.copy' ) && false === strpos( $bar( $html ), 'pressshot-share__tile' ) && false === strpos( $html, 'pressshot-share__title' ) );
$check( 'folder page shows the folder name and its subfolder', false !== strpos( $html, 'Top' . $tag ) && false !== strpos( $html, 'Sub' . $tag ) );
$check( 'folder page grids shots in the tree', false !== strpos( $html, 'Top shot ' . $tag ) && false !== strpos( $html, 'Sub shot ' . $tag ) && substr_count( $html, 'pressshot-share__tile' ) === 2 );
$check( 'folder page thumbnails use the medium size and tiles link with ?shot=', false !== strpos( $html, 'size=medium' ) && false !== strpos( $html, 'shot=' . $in_top ) );
$check( 'folder page hides other folders, their shots, and the uploader', false === strpos( $html, 'Elsewhere' . $tag ) && false === strpos( $html, 'Outside shot' ) && false === strpos( $html, 'Uploader' . $tag ) );
$html = $render( $flink['token'], $in_sub );
$check( 'folder ?shot= inside the tree shows that shot with a back link', false !== strpos( $html, 'Sub shot ' . $tag ) && false !== strpos( $html, 'Back to all shots' ) && false !== strpos( $html, '>Download</a>' ) && false === strpos( $html, 'Top shot' ) );
$check( 'opened folder shot keeps a back link', 1 === preg_match( '#<a class="pressshot-share__back" href="' . preg_quote( esc_url( pressshot_link_url( $flink['token'] ) ), '#' ) . '"[^>]*>#', $bar( $html ) ) && 1 === preg_match( '#<h1 class="pressshot-share__name"[^>]*>Sub shot ' . $tag . '</h1>#', $bar( $html ) ) );
$html = $render( $flink['token'], $outside );
$check( 'folder ?shot= outside the tree is unavailable (404)', false !== strpos( $html, $gone ) && in_array( 404, $statuses, true ) && false === strpos( $html, 'Outside shot' ) );
$html = $render( $flink['token'], 'abc' );
$check( 'folder ?shot=abc is ignored and shows the folder', false !== strpos( $html, 'Top shot ' . $tag ) );
$html = $render( $flink['token'], '999999999' );
$check( 'folder ?shot= for a missing shot is unavailable', false !== strpos( $html, $gone ) );

// Unavailable.
pressshot_link_revoke( 'shot', $shot );
$html = $render( $token );
$check( 'a revoked link shows "This link is no longer available." with a 404', false !== strpos( $html, $gone ) && in_array( 404, $statuses, true ) );
$check( 'the unavailable page shows nothing of the shot', false === strpos( $html, 'Quarterly chart' ) );
$check( 'revoked link still shows the unavailable view', false !== strpos( $render( $token, null, '<!-- wp:pressshot/share-view {"showSiteName":true} --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- /wp:pressshot/share-view -->' ), $gone ) && in_array( 404, $statuses, true ) && false === strpos( $html, 'pressshot-share__bar' ) );
$html = $render( $random );
$check( 'an unknown token is the same unavailable page', false !== strpos( $html, $gone ) && in_array( 404, $statuses, true ) );
$html = $render( 'nope' );
$check( 'a malformed token is the same unavailable page', false !== strpos( $html, $gone ) && in_array( 404, $statuses, true ) );
$html = $render( '' );
$check( 'no token renders nothing and no 404', '' === $html && ! in_array( 404, $statuses, true ) );

// Headers, robots, rewrite.
$GLOBALS['wp']->matched_rule = '^s/([A-Za-z0-9]{32})/?$';
set_query_var( 'pressshot_share', $flink['token'] );
$headers = wp_get_nocache_headers();
$check( 'share requests send private, no-store and X-Robots-Tag noindex', 'private, no-store' === $headers['Cache-Control'] && 'noindex' === $headers['X-Robots-Tag'] );
$check( 'wp_robots adds noindex and nofollow on share requests', ! empty( apply_filters( 'wp_robots', array() )['noindex'] ) && ! empty( apply_filters( 'wp_robots', array() )['nofollow'] ) );
$check( 'the document title is the folder name', 0 === strpos( wp_get_document_title(), 'Top' . $tag ) );
set_query_var( 'pressshot_share', '' );
$GLOBALS['wp']->matched_rule = '';
$check( 'other requests are untouched', ! isset( wp_get_nocache_headers()['X-Robots-Tag'] ) && empty( apply_filters( 'wp_robots', array() )['noindex'] ) );
$check( 'the share block and template are registered', WP_Block_Type_Registry::get_instance()->is_registered( 'pressshot/share-view' ) && null !== get_block_template( 'pressshot//share' ) );
$check( 'the share query var is public', in_array( 'pressshot_share', apply_filters( 'query_vars', array() ), true ) );
pressshot_share_activate();
$rules = get_option( 'rewrite_rules' );
$check( 'activation flushes the share rule', isset( $rules['^s/([A-Za-z0-9]{32})/?$'] ) && 'index.php?pressshot_share=$matches[1]' === $rules['^s/([A-Za-z0-9]{32})/?$'] );
$check( 'activation stores the rewrite version', PRESSSHOT_VERSION === get_option( 'pressshot_rewrite_version' ) );
$check( 'no flush when the version matches', false === pressshot_share_maybe_flush() );
update_option( 'pressshot_rewrite_version', '0.0.0-old' );
delete_option( 'rewrite_rules' );
$check( 'a changed version flushes and records it', true === pressshot_share_maybe_flush() && PRESSSHOT_VERSION === get_option( 'pressshot_rewrite_version' ) && isset( get_option( 'rewrite_rules' )['^s/([A-Za-z0-9]{32})/?$'] ) );
pressshot_share_deactivate();
$rules = (array) get_option( 'rewrite_rules' );
$check( 'deactivation removes the share and /shots rules', ! isset( $rules['^s/([A-Za-z0-9]{32})/?$'] ) && ! isset( $rules[ pressshot_shots_rule() ] ) );
$check( 'deactivation forgets the rewrite version so reactivating flushes', false === get_option( 'pressshot_rewrite_version' ) );
pressshot_share_activate();
$check( 'reactivating restores both rules', isset( get_option( 'rewrite_rules' )['^s/([A-Za-z0-9]{32})/?$'] ) && isset( get_option( 'rewrite_rules' )[ pressshot_shots_rule() ] ) );
