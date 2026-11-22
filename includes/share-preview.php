<?php
/**
 * Link previews for share pages: Open Graph and Twitter card tags, so a pasted /s/{token} link
 * unfurls with the shot itself in Slack, iMessage, and other apps. A dead link gets no tags.
 * Chat apps keep their own copy of a preview image, so stopping sharing can't take one back.
 */

/**
 * The preview image's width and height: the 1536px or large size the media route serves, else
 * the original. Empty when the metadata doesn't say.
 */
function pressshot_share_preview_dimensions( $id ) {
	$meta = wp_get_attachment_metadata( $id );
	if ( ! is_array( $meta ) ) {
		return array();
	}
	foreach ( array( '1536x1536', 'large' ) as $key ) {
		if ( ! empty( $meta['sizes'][ $key ]['width'] ) && ! empty( $meta['sizes'][ $key ]['height'] ) ) {
			return array( (int) $meta['sizes'][ $key ]['width'], (int) $meta['sizes'][ $key ]['height'] );
		}
	}
	return ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ? array( (int) $meta['width'], (int) $meta['height'] ) : array();
}

/**
 * The preview tags for the current share request, as a list of
 * array( 'attr' => 'property'|'name', 'key' => string, 'content' => string ).
 * Empty unless this is a /s/{token} request for a live link.
 */
function pressshot_share_preview_tags() {
	if ( ! pressshot_share_is_request() ) {
		return array();
	}
	$link = pressshot_link_resolve( pressshot_share_token() );
	if ( ! $link ) {
		return array();
	}
	$token = $link['token'];
	$url   = pressshot_link_url( $token );
	$shot  = 'shot' === $link['kind'] ? (int) $link['target'] : 0;
	if ( 'folder' === $link['kind'] ) {
		$requested = pressshot_share_requested_shot();
		if ( $requested && pressshot_link_covers_shot( $link, $requested ) ) {
			$shot = $requested;
			$url  = add_query_arg( 'shot', $requested, $url );
		}
	}

	$image  = '';
	$width  = 0;
	$height = 0;
	if ( $shot ) {
		// Built from the plain token address, never pressshot_share_media_url(): a signed-in visitor's nonce must not leak into a preview.
		$public = pressshot_public_shot( $shot, $token );
		$title  = $public['title'];
		if ( 'screenshot' === $public['kind'] && $public['mediaUrl'] ) {
			$image = add_query_arg( 'size', 'large', $public['mediaUrl'] );
			$dims  = pressshot_share_preview_dimensions( $shot );
			if ( $dims ) {
				list( $width, $height ) = $dims;
			}
		} elseif ( $public['posterUrl'] ) {
			$image = $public['posterUrl'];
		}
	} else {
		$term  = get_term( $link['target'], 'pressshot_folder' );
		$title = $term && ! is_wp_error( $term ) ? $term->name : '';
	}

	$site = get_bloginfo( 'name', 'display' );
	$tags = array(
		array( 'property', 'og:type', 'website' ),
		array( 'property', 'og:site_name', $site ),
		array( 'property', 'og:title', $title ),
		/* translators: %s: the site's name. */
		array( 'property', 'og:description', sprintf( __( 'Shared from %s', 'pressshot' ), $site ) ),
		array( 'property', 'og:url', $url ),
	);
	if ( $image ) {
		$tags[] = array( 'property', 'og:image', $image );
		if ( $width && $height ) {
			$tags[] = array( 'property', 'og:image:width', (string) $width );
			$tags[] = array( 'property', 'og:image:height', (string) $height );
		}
		$tags[] = array( 'property', 'og:image:alt', $title );
	}
	$tags[] = array( 'name', 'twitter:card', $image ? 'summary_large_image' : 'summary' );

	return array_map(
		function ( $t ) {
			return array(
				'attr'    => $t[0],
				'key'     => $t[1],
				'content' => (string) $t[2],
			);
		},
		$tags
	);
}

add_action(
	'wp_head',
	function () {
		foreach ( pressshot_share_preview_tags() as $t ) {
			printf( '<meta %s="%s" content="%s" />' . "\n", esc_attr( $t['attr'] ), esc_attr( $t['key'] ), esc_attr( $t['content'] ) );
		}
	},
	5
);

// One set of preview tags: Jetpack's would describe the site, not the shot.
add_filter(
	'jetpack_enable_open_graph',
	function ( $enabled ) {
		return pressshot_share_is_request() ? false : $enabled;
	},
	99
);
