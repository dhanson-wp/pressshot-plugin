<?php
/**
 * Integration checks for VideoPress recordings: handing a fresh VideoPress video to the person who
 * uploaded it, and the short-lived viewing pass for the Library viewer and share pages.
 * Studio has no Jetpack, so the pass comes from the pressshot_videopress_pass filter here.
 * Run with bin/test-php.sh. It creates and removes only its own users, shots, and link records.
 */
require __DIR__ . '/harness.php';

$test_guids = array( 'GuIdAaa1', 'GuIdPst1', 'GuIdCch1', 'GuIdJwt1', 'GuIdJwt2' );
foreach ( $test_guids as $guid ) {
	pressshot_videopress_pass_forget( $guid );
}
register_shutdown_function(
	function () use ( $test_guids ) {
		foreach ( $GLOBALS['pressshot_test_made']['attachments'] as $id ) {
			pressshot_link_revoke( 'shot', $id );
		}
		foreach ( $test_guids as $guid ) {
			pressshot_videopress_pass_forget( $guid );
		}
	}
);

$site   = $make_user( 'administrator' ); // Stands in for the site's connection account, which VideoPress uses.
$a      = $make_user( 'author' );
$b      = $make_user( 'author' );
$reader = $make_user( 'subscriber' );

/** A video as VideoPress leaves it: owned by the connection account, not yet a shot. */
$make_vp = function ( $guid, $owner = null, $age = 0 ) use ( $make_shot, $site ) {
	$id = $make_shot( $owner ? $owner : $site, 'video/mp4' );
	delete_post_meta( $id, '_pressshot_capture' );
	update_post_meta( $id, 'videopress_guid', $guid );
	$when = gmdate( 'Y-m-d H:i:s', time() - $age );
	wp_update_post(
		array(
			'ID'             => $id,
			'post_mime_type' => 'video/videopress',
			'post_date_gmt'  => $when,
			'post_date'      => get_date_from_gmt( $when ),
		)
	);
	return $id;
};
$register = function ( $id, $guid = null ) use ( $call ) {
	$params = array( 'source' => 'pressshot' );
	if ( null !== $guid ) {
		$params['videopressGuid'] = $guid;
	}
	return $call( 'POST', "/pressshot/v1/captures/$id/register", $params );
};
$author_of = function ( $id ) {
	clean_post_cache( $id );
	return (int) get_post( $id )->post_author;
};

// Claiming a fresh VideoPress video.
$fresh = $make_vp( 'GuIdAaa1' );
wp_set_current_user( $a );
$check( 'claim: without the VideoPress ID is refused', 403 === $register( $fresh )->get_status() && $site === $author_of( $fresh ) );
$check( 'claim: a wrong VideoPress ID is refused', 403 === $register( $fresh, 'GuIdZzz9' )->get_status() && $site === $author_of( $fresh ) && ! pressshot_is_shot( $fresh ) );
$r = $register( $fresh, 'GuIdAaa1' );
$check( 'claim: the right ID registers it', 200 === $r->get_status() && $fresh === $r->get_data()['id'] && 1 === preg_match( '#/s/[A-Za-z0-9]{32}$#', (string) $r->get_data()['shareUrl'] ) );
$check( 'claim: the uploader becomes its owner', $a === $author_of( $fresh ) && pressshot_is_shot( $fresh ) );
$check( 'claim: registering again is fine', 200 === $register( $fresh, 'GuIdAaa1' )->get_status() && $a === $author_of( $fresh ) );
wp_set_current_user( $b );
$check( 'claim: a registered shot can\'t be taken by someone else', 403 === $register( $fresh, 'GuIdAaa1' )->get_status() && $a === $author_of( $fresh ) );

$old = $make_vp( 'GuIdOld1', null, HOUR_IN_SECONDS );
$check( 'claim: an hour-old video is refused', 403 === $register( $old, 'GuIdOld1' )->get_status() && $site === $author_of( $old ) );
$other_plain = $make_shot( $a, 'video/mp4' );
delete_post_meta( $other_plain, '_pressshot_capture' );
update_post_meta( $other_plain, 'videopress_guid', 'GuIdPln1' );
$check( 'claim: a regular upload by someone else is refused', 403 === $register( $other_plain, 'GuIdPln1' )->get_status() && $a === $author_of( $other_plain ) );
$image = $make_shot( $site );
delete_post_meta( $image, '_pressshot_capture' );
$check( 'claim: an image is refused', 403 === $register( $image, 'GuIdImg1' )->get_status() );
wp_set_current_user( $reader );
$sub = $make_vp( 'GuIdSub1' );
$check( 'claim: a subscriber is refused', 403 === $register( $sub, 'GuIdSub1' )->get_status() && $site === $author_of( $sub ) );
wp_set_current_user( 0 );
$check( 'claim: signed out is refused', in_array( $register( $sub, 'GuIdSub1' )->get_status(), array( 401, 403 ), true ) );

// The viewing pass.
$passes = array();
$mint   = function ( $pass, $guid ) use ( &$passes ) {
	$passes[] = $guid;
	return 'pass-for-' . $guid;
};
add_filter( 'pressshot_videopress_pass', $mint, 10, 2 );
$check( 'pass: helper mints for a guid', 'pass-for-GuIdAaa1' === pressshot_videopress_pass( 'GuIdAaa1' ) );
$check( 'pass: helper refuses a malformed guid', null === pressshot_videopress_pass( 'bad guid!' ) );

wp_set_current_user( $b );
$p = $call( 'GET', "/pressshot/v1/shots/$fresh/videopress-pass" );
$check( 'pass: a teammate gets one for a VideoPress shot', 200 === $p->get_status() && 'pass-for-GuIdAaa1' === $p->get_data()['pass'] && 'GuIdAaa1' === $p->get_data()['guid'] );
$check( 'pass: the response is never cached', 'private, no-store' === ( $p->get_headers()['Cache-Control'] ?? '' ) );
$local = $make_shot( $b, 'video/mp4' );
$check( 'pass: a shot without VideoPress is 404', 404 === $call( 'GET', "/pressshot/v1/shots/$local/videopress-pass" )->get_status() );
$check( 'pass: an unregistered video is 404', 404 === $call( 'GET', "/pressshot/v1/shots/$old/videopress-pass" )->get_status() );
wp_set_current_user( $reader );
$check( 'pass: a subscriber is refused', 403 === $call( 'GET', "/pressshot/v1/shots/$fresh/videopress-pass" )->get_status() );
wp_set_current_user( 0 );
$check( 'pass: signed out is refused', in_array( $call( 'GET', "/pressshot/v1/shots/$fresh/videopress-pass" )->get_status(), array( 401, 403 ), true ) );
remove_filter( 'pressshot_videopress_pass', $mint, 10 );
pressshot_videopress_pass_forget( 'GuIdAaa1' );
wp_set_current_user( $b );
$none = $call( 'GET', "/pressshot/v1/shots/$fresh/videopress-pass" );
$check( 'pass: without Jetpack the pass is null, not an error', 200 === $none->get_status() && null === $none->get_data()['pass'] );
add_filter( 'pressshot_videopress_pass', $mint, 10, 2 );

// Share pages hand the pass to the player only while the link is live.
wp_set_current_user( 0 );
$link   = pressshot_link_for( 'shot', $fresh );
$render = function ( $token ) {
	set_query_var( 'pressshot_share', $token );
	unset( $_GET['shot'] );
	return do_blocks( '<!-- wp:pressshot/share-view /-->' );
};
$passes = array();
$html   = $render( $link['token'] );
$check( 'share: the player frame carries the pass', false !== strpos( $html, 'data-pressshot-pass="pass-for-GuIdAaa1"' ) && false !== strpos( $html, 'videopress.com/embed/GuIdAaa1' ) );
$check( 'share: the page loads the pass handoff script', wp_script_is( 'pressshot-videopress-pass', 'enqueued' ) );
pressshot_link_revoke( 'shot', $fresh );
pressshot_videopress_pass_forget( 'GuIdAaa1' );
$passes = array();
$html   = $render( $link['token'] );
$check( 'share: a revoked link mints no pass', array() === $passes && false === strpos( $html, 'pass-for-' ) && false !== strpos( $html, 'This link is no longer available.' ) );

$folder = wp_insert_term( 'VP folder ' . wp_generate_password( 5, false ), 'pressshot_folder' );
wp_set_object_terms( $fresh, array( (int) $folder['term_id'] ), 'pressshot_folder' );
$flink  = pressshot_link_create( 'folder', (int) $folder['term_id'] );
$passes = array();
$html   = $render( $flink['token'] );
$check( 'share: a folder page mints nothing until a shot is opened', array() === $passes );
set_query_var( 'pressshot_share', $flink['token'] );
$_GET['shot'] = (string) $fresh;
$html = do_blocks( '<!-- wp:pressshot/share-view /-->' );
unset( $_GET['shot'] );
$check( 'share: an opened folder shot carries the pass', false !== strpos( $html, 'data-pressshot-pass="pass-for-GuIdAaa1"' ) );
pressshot_link_revoke( 'folder', (int) $folder['term_id'] );
wp_delete_term( (int) $folder['term_id'], 'pressshot_folder' );

// Posters: the Library shows the still VideoPress made, through a route that adds the viewing pass.
$vp_meta = function ( $id, $videopress ) {
	wp_update_attachment_metadata( $id, array( 'videopress' => $videopress ) );
};
$vp_meta(
	$fresh,
	array(
		'guid'          => 'GuIdAaa1',
		'poster'        => 'https://videos.files.wordpress.com/GuIdAaa1/clip_mp4_vp9_2160p.original.jpg',
		'file_url_base' => array( 'https' => 'https://videos.files.wordpress.com/GuIdAaa1/' ),
		'files'         => array( 'hd' => array( 'original_img' => 'clip_mp4_hd.original.jpg' ) ),
	)
);
$check( 'poster: prefers the 720p still', 'https://videos.files.wordpress.com/GuIdAaa1/clip_mp4_hd.original.jpg' === pressshot_videopress_poster( $fresh ) );
$only_poster = $make_vp( 'GuIdPst1', $a );
$vp_meta( $only_poster, array( 'poster' => 'https://videos.files.wordpress.com/GuIdPst1/clip.original.jpg' ) );
$check( 'poster: falls back to the poster', 'https://videos.files.wordpress.com/GuIdPst1/clip.original.jpg' === pressshot_videopress_poster( $only_poster ) );
$elsewhere = $make_vp( 'GuIdEvl1', $a );
$vp_meta( $elsewhere, array( 'poster' => 'https://example.com/clip.jpg' ) );
$check( 'poster: ignores a poster that isn\'t on VideoPress', null === pressshot_videopress_poster( $elsewhere ) );
$check( 'poster: a local recording has none', null === pressshot_videopress_poster( $local ) );

wp_set_current_user( $b );
$dto = $call( 'GET', "/pressshot/v1/shots/$fresh" )->get_data();
$check( 'poster: the shot carries its poster route', rest_url( "pressshot/v1/shots/$fresh/poster" ) === $dto['posterUrl'] );
$check( 'poster: a local recording carries none', null === $call( 'GET', "/pressshot/v1/shots/$local" )->get_data()['posterUrl'] );

$r = $call( 'GET', "/pressshot/v1/shots/$fresh/poster" );
$check( 'poster: the route redirects to the still with a pass', 302 === $r->get_status() && 'https://videos.files.wordpress.com/GuIdAaa1/clip_mp4_hd.original.jpg?metadata_token=pass-for-GuIdAaa1' === ( $r->get_headers()['Location'] ?? '' ) );
$check( 'poster: the redirect is never cached', 'private, no-store' === ( $r->get_headers()['Cache-Control'] ?? '' ) );
$check( 'poster: no poster is 404', 404 === $call( 'GET', "/pressshot/v1/shots/$local/poster" )->get_status() );
remove_filter( 'pressshot_videopress_pass', $mint, 10 );
pressshot_videopress_pass_forget( 'GuIdAaa1' );
$r = $call( 'GET', "/pressshot/v1/shots/$fresh/poster" );
$check( 'poster: without a pass it redirects to the plain still', 302 === $r->get_status() && 'https://videos.files.wordpress.com/GuIdAaa1/clip_mp4_hd.original.jpg' === ( $r->get_headers()['Location'] ?? '' ) );
add_filter( 'pressshot_videopress_pass', $mint, 10, 2 );
wp_set_current_user( $reader );
$check( 'poster: a subscriber is refused', 403 === $call( 'GET', "/pressshot/v1/shots/$fresh/poster" )->get_status() );
wp_set_current_user( 0 );
$check( 'poster: signed out is refused', in_array( $call( 'GET', "/pressshot/v1/shots/$fresh/poster" )->get_status(), array( 401, 403 ), true ) );

// Folder share pages: recording tiles show the still through a route the link guards.
wp_set_current_user( 0 );
$pfolder = wp_insert_term( 'Poster folder ' . wp_generate_password( 5, false ), 'pressshot_folder' );
$pfid    = (int) $pfolder['term_id'];
wp_set_object_terms( $fresh, array( $pfid ), 'pressshot_folder' );
wp_set_object_terms( $local, array( $pfid ), 'pressshot_folder' );
$plink  = pressshot_link_create( 'folder', $pfid );
$ptoken = $plink['token'];
$proute = "/pressshot/v1/links/$ptoken/shots/$fresh/poster";
$passes = array();
$html   = $render( $ptoken );
$check( 'share poster: the folder tile shows the still', false !== strpos( $html, 'src="' . esc_url( rest_url( ltrim( $proute, '/' ) ) ) . '"' ) );
$check( 'share poster: the tile keeps the Recording fallback and a play badge', 2 === substr_count( $html, 'pressshot-share__placeholder' ) && 1 === substr_count( $html, 'pressshot-share__play' ) );
$check( 'share poster: a local recording gets no still', 1 === substr_count( $html, '/poster' ) );
$check( 'share poster: rendering the page still mints nothing', array() === $passes );
$viewed = array_column( $call( 'GET', "/pressshot/v1/links/$ptoken" )->get_data()['shots'], 'posterUrl', 'id' );
$check( 'share poster: the link view carries the poster route', rest_url( ltrim( $proute, '/' ) ) === ( $viewed[ $fresh ] ?? null ) && array_key_exists( $local, $viewed ) && null === $viewed[ $local ] );

$r = $call( 'GET', $proute );
$check( 'share poster: a live link redirects to the still with a pass', 302 === $r->get_status() && 'https://videos.files.wordpress.com/GuIdAaa1/clip_mp4_hd.original.jpg?metadata_token=pass-for-GuIdAaa1' === ( $r->get_headers()['Location'] ?? '' ) );
$check( 'share poster: the redirect is never cached or indexed', 'private, no-store' === ( $r->get_headers()['Cache-Control'] ?? '' ) && 'noindex' === ( $r->get_headers()['X-Robots-Tag'] ?? '' ) );
$check( 'share poster: a recording without a still is 404', 404 === $call( 'GET', "/pressshot/v1/links/$ptoken/shots/$local/poster" )->get_status() );
$check( 'share poster: a shot outside the folder is 404', 404 === $call( 'GET', "/pressshot/v1/links/$ptoken/shots/$only_poster/poster" )->get_status() );
$check( 'share poster: a bad token is 404', 404 === $call( 'GET', "/pressshot/v1/links/nope/shots/$fresh/poster" )->get_status() );
$single = pressshot_link_create( 'shot', $fresh );
$check( 'share poster: a single-shot link covers its shot', 302 === $call( 'GET', "/pressshot/v1/links/{$single['token']}/shots/$fresh/poster" )->get_status() );
pressshot_link_revoke( 'folder', $pfid );
pressshot_videopress_pass_forget( 'GuIdAaa1' );
$passes = array();
$r      = $call( 'GET', $proute );
$check( 'share poster: a revoked link is 404 and mints nothing', 404 === $r->get_status() && array() === $passes && ! isset( $r->get_headers()['Location'] ) );
wp_delete_term( $pfid, 'pressshot_folder' );

// Reusing passes: signed-out poster and player views shouldn't each ask WordPress.com for a new one.
pressshot_videopress_pass_forget( 'GuIdAaa1' );
$passes = array();
$first  = pressshot_videopress_pass( 'GuIdCch1' );
$second = pressshot_videopress_pass( 'GuIdCch1' );
$check( 'cache: a second request for the same video reuses the pass', 'pass-for-GuIdCch1' === $first && $first === $second && array( 'GuIdCch1' ) === $passes );
pressshot_videopress_pass( 'GuIdAaa1' );
$check( 'cache: each video gets its own pass', array( 'GuIdCch1', 'GuIdAaa1' ) === $passes );
$cached = get_transient( 'pressshot_vp_pass_GuIdCch1' );
$check( 'cache: an opaque pass is reused for at most the reuse window', is_array( $cached ) && $cached['until'] <= time() + PRESSSHOT_VIDEOPRESS_PASS_REUSE && $cached['until'] > time() );
set_transient( 'pressshot_vp_pass_GuIdCch1', array( 'pass' => 'stale', 'until' => time() - 1 ), 60 );
$passes = array();
$check( 'cache: a pass past its window is minted again', 'pass-for-GuIdCch1' === pressshot_videopress_pass( 'GuIdCch1' ) && array( 'GuIdCch1' ) === $passes );

$single = pressshot_link_create( 'shot', $fresh );
$route  = "/pressshot/v1/links/{$single['token']}/shots/$fresh/poster";
pressshot_videopress_pass_forget( 'GuIdAaa1' );
$passes = array();
$views  = array();
for ( $i = 0; $i < 5; $i++ ) {
	$views[] = $call( 'GET', $route )->get_headers()['Location'] ?? '';
}
$check( 'cache: five signed-out poster views mint one pass', array( 'GuIdAaa1' ) === $passes && 1 === count( array_unique( $views ) ) && false !== strpos( $views[0], 'metadata_token=pass-for-GuIdAaa1' ) );
$html = $render( $single['token'] );
$check( 'cache: the share player reuses the same pass', array( 'GuIdAaa1' ) === $passes && false !== strpos( $html, 'data-pressshot-pass="pass-for-GuIdAaa1"' ) );
pressshot_link_revoke( 'shot', $fresh );
$r    = $call( 'GET', $route );
$html = $render( $single['token'] );
$check( 'cache: a revoked link gets no pass even while one is cached', is_array( get_transient( 'pressshot_vp_pass_GuIdAaa1' ) ) && 404 === $r->get_status() && ! isset( $r->get_headers()['Location'] ) && false === strpos( $html, 'pass-for-' ) );
$expiring = pressshot_link_create( 'shot', $fresh, time() + 2 );
$check( 'cache: a link about to expire still gets the cached pass', 302 === $call( 'GET', "/pressshot/v1/links/{$expiring['token']}/shots/$fresh/poster" )->get_status() );
update_post_meta( pressshot_link_post_ids( 'shot', $fresh )[0], '_pressshot_link_expires', time() - 1 );
$r = $call( 'GET', "/pressshot/v1/links/{$expiring['token']}/shots/$fresh/poster" );
$check( 'cache: an expired link gets no pass even while one is cached', 404 === $r->get_status() && ! isset( $r->get_headers()['Location'] ) );
pressshot_link_revoke( 'shot', $fresh );
remove_filter( 'pressshot_videopress_pass', $mint, 10 );

// A JWT pass is never reused past a margin before its own expiry.
$jwt = function ( $exp ) {
	$part = function ( $data ) {
		return rtrim( strtr( base64_encode( wp_json_encode( $data ) ), '+/', '-_' ), '=' );
	};
	return $part( array( 'alg' => 'HS256' ) ) . '.' . $part( array( 'exp' => $exp ) ) . '.sig';
};
$check( 'ttl: an opaque pass gets the reuse window', PRESSSHOT_VIDEOPRESS_PASS_REUSE === pressshot_videopress_pass_ttl( 'opaque-pass' ) );
$check( 'ttl: a long-lived JWT gets the reuse window', PRESSSHOT_VIDEOPRESS_PASS_REUSE === pressshot_videopress_pass_ttl( $jwt( time() + DAY_IN_SECONDS ) ) );
$check( 'ttl: a JWT that expires soon is reused only until the margin', abs( 60 - pressshot_videopress_pass_ttl( $jwt( time() + PRESSSHOT_VIDEOPRESS_PASS_MARGIN + 60 ) ) ) <= 1 );
$check( 'ttl: a JWT inside the margin isn\'t reused', 0 === pressshot_videopress_pass_ttl( $jwt( time() + 30 ) ) );
$short = function () use ( $jwt ) {
	return $jwt( time() + 30 );
};
add_filter( 'pressshot_videopress_pass', $short );
pressshot_videopress_pass( 'GuIdJwt1' );
$check( 'ttl: a pass inside the margin is handed out but not cached', false === get_transient( 'pressshot_vp_pass_GuIdJwt1' ) );
remove_filter( 'pressshot_videopress_pass', $short );
$long = function () use ( $jwt ) {
	return $jwt( time() + HOUR_IN_SECONDS );
};
add_filter( 'pressshot_videopress_pass', $long );
pressshot_videopress_pass( 'GuIdJwt2' );
$check( 'ttl: a long-lived JWT is cached', is_array( get_transient( 'pressshot_vp_pass_GuIdJwt2' ) ) );
remove_filter( 'pressshot_videopress_pass', $long );
