<?php
/**
 * Public share pages at /s/{token}.
 *
 * A share link opens a server-rendered page anyone can view without signing in. The page
 * follows the active block theme's header, footer, and styles through the block template
 * pressshot//share (a theme can override it with its own templates/share.html). It is built from
 * the same functions the anonymous REST routes use (pressshot_link_resolve, pressshot_link_view,
 * pressshot_public_shot), so it shows exactly what the routes would, and it never shows the
 * uploader or anything outside the shared tree.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The share rewrite rule's pattern. Visitors reach /s/{token}; the token is 32 characters [A-Za-z0-9]. */
function pressshot_share_rule() {
	return '^s/([A-Za-z0-9]{32})/?$';
}

function pressshot_share_add_rewrite_rule() {
	add_rewrite_rule( pressshot_share_rule(), 'index.php?pressshot_share=$matches[1]', 'top' );
}
add_action( 'init', 'pressshot_share_add_rewrite_rule' );

add_filter(
	'query_vars',
	function ( $vars ) {
		$vars[] = 'pressshot_share';
		return $vars;
	}
);

/** True when the request was matched by the given rewrite rule (so a stray query var on another URL doesn't count). */
function pressshot_request_matched_rule( $rule ) {
	global $wp;
	return $wp instanceof WP && $rule === $wp->matched_rule;
}

/** Activation: add the share and /shots rules and flush so both work at once. */
function pressshot_share_activate() {
	pressshot_share_add_rewrite_rule();
	pressshot_shots_add_rewrite_rule();
	flush_rewrite_rules( false );
	update_option( 'pressshot_rewrite_version', PRESSSHOT_VERSION );
}

/** Deactivation: drop the share and /shots rules and forget the version, so reactivating flushes again. */
function pressshot_share_deactivate() {
	global $wp_rewrite;
	unset( $wp_rewrite->extra_rules_top[ pressshot_share_rule() ], $wp_rewrite->extra_rules_top[ pressshot_shots_rule() ] );
	flush_rewrite_rules( false );
	delete_option( 'pressshot_rewrite_version' );
}

/** A changed plugin version (for example after syncing new code into a site) re-flushes once, so a new rule needs no manual flush. */
function pressshot_share_maybe_flush() {
	if ( get_option( 'pressshot_rewrite_version' ) !== PRESSSHOT_VERSION ) {
		flush_rewrite_rules( false );
		update_option( 'pressshot_rewrite_version', PRESSSHOT_VERSION );
		return true;
	}
	return false;
}
add_action( 'init', 'pressshot_share_maybe_flush', 99 );

add_action(
	'init',
	function () {
		register_block_type( __DIR__ . '/../blocks/share-view' );
		if ( function_exists( 'register_block_template' ) ) {
			register_block_template(
				'pressshot//share',
				array(
					'title'       => __( 'Shared shot', 'pressshot' ),
					'description' => __( 'The public page for a PressShot share link.', 'pressshot' ),
					'content'     => (string) file_get_contents( __DIR__ . '/../templates/share.html' ),
				)
			);
		}
	}
);

/** The token this request is for, or '' when it isn't a share request. Not checked against links. */
function pressshot_share_token() {
	// Hosts can send no-cache headers before the main query exists (WordPress.com does during plugins_loaded).
	if ( ! isset( $GLOBALS['wp_query'] ) || ! ( $GLOBALS['wp_query'] instanceof WP_Query ) ) {
		return '';
	}
	$token = get_query_var( 'pressshot_share' );
	return is_string( $token ) ? $token : '';
}

/** A share request came through the /s/{token} rule; ?pressshot_share= on any other URL is an ordinary request. */
function pressshot_share_is_request() {
	return '' !== pressshot_share_token() && pressshot_request_matched_rule( pressshot_share_rule() );
}

/**
 * A share page's media address. For a signed-in visitor it carries their REST nonce, because a private
 * WordPress.com site refuses REST requests that only bring a cookie (the media would never load).
 * Signed-out visitors get the plain token address. Share pages are never cached, so the nonce stays per person.
 */
function pressshot_share_media_url( $url ) {
	return is_user_logged_in() ? add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), $url ) : $url;
}

/** The shot id in ?shot=, or 0. */
function pressshot_share_requested_shot() {
	$shot = isset( $_GET['shot'] ) ? $_GET['shot'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only page.
	return is_string( $shot ) && ctype_digit( $shot ) && strlen( $shot ) < 15 ? (int) $shot : 0;
}

/** The share page is its own request: not the blog, not a 404 (the page sets its own status). */
add_filter(
	'pre_handle_404',
	function ( $handled ) {
		return pressshot_share_is_request() ? true : $handled;
	}
);

// /s/{token} is the canonical URL; don't redirect it to a trailing-slash variant.
add_filter(
	'redirect_canonical',
	function ( $redirect ) {
		return pressshot_share_is_request() ? false : $redirect;
	}
);

add_filter(
	'posts_pre_query',
	function ( $posts, $query ) {
		return $query->is_main_query() && pressshot_share_is_request() ? array() : $posts;
	},
	10,
	2
);

add_action(
	'template_redirect',
	function () {
		global $wp_query;
		if ( ! pressshot_share_is_request() ) {
			return;
		}
		$wp_query->is_home = false;
		$wp_query->is_404  = false;
		nocache_headers();
	},
	1
);

add_filter(
	'template_include',
	function ( $template ) {
		if ( ! pressshot_share_is_request() ) {
			return $template;
		}
		if ( wp_is_block_theme() ) {
			$block_template = locate_block_template( '', 'share', array( 'share' ) );
			return $block_template ? $block_template : $template;
		}
		return __DIR__ . '/../templates/share-classic.php';
	},
	99
);

// Share pages are private: never cached by a shared cache (so a revoked link stops working) and never indexed.
add_filter(
	'nocache_headers',
	function ( $headers ) {
		if ( pressshot_share_is_request() ) {
			$headers['Cache-Control'] = 'private, no-store';
			$headers['X-Robots-Tag']  = 'noindex';
		}
		return $headers;
	}
);

add_filter(
	'wp_robots',
	function ( $robots ) {
		if ( pressshot_share_is_request() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}
);

add_filter(
	'document_title_parts',
	function ( $parts ) {
		if ( ! pressshot_share_is_request() ) {
			return $parts;
		}
		$link  = pressshot_link_resolve( pressshot_share_token() );
		$title = __( 'Link unavailable', 'pressshot' );
		if ( $link ) {
			$shot = 'folder' === $link['kind'] ? pressshot_share_requested_shot() : 0;
			if ( $shot && pressshot_link_covers_shot( $link, $shot ) ) {
				$title = get_the_title( $shot );
			} elseif ( 'shot' === $link['kind'] ) {
				$title = get_the_title( $link['target'] );
			} else {
				$term  = get_term( $link['target'], 'pressshot_folder' );
				$title = $term && ! is_wp_error( $term ) ? $term->name : $title;
			}
		}
		return array(
			'title' => $title,
			'site'  => get_bloginfo( 'name', 'display' ),
		);
	}
);

/** The unavailable view, with a 404 and no-cache headers. */
function pressshot_share_unavailable() {
	status_header( 404 );
	nocache_headers();
	return sprintf(
		'<div %s><p class="pressshot-share__unavailable">%s</p></div>',
		get_block_wrapper_attributes(),
		esc_html__( 'This link is no longer available.', 'pressshot' )
	);
}

/** The Copy link button. The copied URL comes from the block's interactivity context. */
function pressshot_share_copy_button() {
	return sprintf(
		'<button type="button" class="pressshot-share__copy wp-element-button" data-wp-on--click="actions.copy"><span data-wp-bind--hidden="context.copied">%s</span><span hidden data-wp-bind--hidden="!context.copied">%s</span></button>',
		esc_html__( 'Copy link', 'pressshot' ),
		esc_html__( 'Copied', 'pressshot' )
	);
}

/**
 * The PressShot mark as inline SVG that takes the surrounding text color. Hidden from screen
 * readers; it only decorates the name beside it. Same paths as the Library sidebar's mark.
 *
 * @param int $size Width and height in pixels.
 */
function pressshot_mark_svg( $size = 24 ) {
	return sprintf(
		'<svg class="pressshot-share__logo" xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M3.5 3.2 11.5 1.5Q14 1 14 3.5v.3L6.5 5.4Q3.5 6 3.5 9v7.3Q1 16.5 1 14V6.2q0-2.5 2.5-3Z"/><path fill-rule="evenodd" d="m7.5 7 8.5-1.8q3-.6 3 2.3V15q0 2.5-2.5 3L8 19.3q-3 .6-3-2.3V10q0-2.5 2.5-3Zm6.4 1.3 2-.4q.8-.2.8.6v1.8q0 .7-.7.9l-2 .4q-.8.2-.8-.6V9.2q0-.7.7-.9Z"/></svg>',
		(int) $size
	);
}

/** The site's logo for the share bar: its site icon when it has one, otherwise the PressShot mark. */
function pressshot_share_site_logo() {
	$icon = get_site_icon_url( 64 );
	if ( $icon ) {
		return sprintf( '<img class="pressshot-share__logo" src="%s" alt="" width="24" height="24">', esc_url( $icon ) );
	}
	return pressshot_mark_svg( 24 );
}

/**
 * The slim top bar: the site name (when the block asks for it), a back link, the name, then the
 * actions and any inner blocks at the right. The name stays small; the page is about the image.
 *
 * @param string $name       The shot or folder name.
 * @param string $actions    Action buttons, already escaped.
 * @param array  $attributes Block attributes (showSiteName).
 * @param string $content    Rendered inner blocks.
 * @param string $back_url   The folder page, when a shot was opened from a folder link.
 */
function pressshot_share_bar( $name, $actions, array $attributes = array(), $content = '', $back_url = '' ) {
	$site = ! empty( $attributes['showSiteName'] )
		? sprintf( '<a class="pressshot-share__site" href="%s">%s<span class="pressshot-share__site-name">%s</span></a>', esc_url( home_url( '/' ) ), pressshot_share_site_logo(), esc_html( get_bloginfo( 'name', 'display' ) ) )
		: '';
	$back = $back_url
		? sprintf( '<a class="pressshot-share__back" href="%s">%s</a>', esc_url( $back_url ), esc_html__( 'Back to all shots', 'pressshot' ) )
		: '';
	$extras = '' !== trim( (string) $content ) ? '<div class="pressshot-share__extras">' . $content . '</div>' : '';
	return sprintf(
		'<div class="pressshot-share__bar">%s%s<h1 class="pressshot-share__name" title="%s">%s</h1><div class="pressshot-share__actions">%s</div>%s</div>',
		$site,
		$back,
		esc_attr( $name ),
		esc_html( $name ),
		$actions,
		$extras
	);
}

/**
 * One shot: the top bar (name, Download when there's a local file, Copy link), then the image or
 * video filling the rest of the window. '' when the shot has nothing to show.
 */
function pressshot_share_shot_html( array $shot, $back_url = '', array $attributes = array(), $content = '' ) {
	if ( $shot['mediaUrl'] && 'recording' === $shot['kind'] ) {
		$media = sprintf( '<video controls playsinline preload="metadata" src="%s"></video>', esc_url( pressshot_share_media_url( $shot['mediaUrl'] ) ) );
	} elseif ( $shot['mediaUrl'] ) {
		$size  = $shot['width'] && $shot['height'] ? sprintf( ' width="%d" height="%d"', $shot['width'], $shot['height'] ) : '';
		$media = sprintf( '<img src="%s" alt="%s"%s>', esc_url( pressshot_share_media_url( $shot['mediaUrl'] ) ), esc_attr( $shot['title'] ), $size );
	} elseif ( $shot['videopressGuid'] ) {
		// Only reached for a live link, so this is where a revoked link stops getting passes.
		$pass  = pressshot_videopress_pass( $shot['videopressGuid'] );
		$media = sprintf(
			'<iframe src="%s" title="%s" allow="fullscreen; clipboard-write" allowfullscreen%s></iframe>',
			esc_url( 'https://videopress.com/embed/' . rawurlencode( $shot['videopressGuid'] ) ),
			esc_attr( $shot['title'] ),
			$pass ? sprintf( ' data-pressshot-guid="%s" data-pressshot-pass="%s"', esc_attr( $shot['videopressGuid'] ), esc_attr( $pass ) ) : ''
		);
		if ( $pass ) {
			wp_enqueue_script( 'pressshot-videopress-pass', plugins_url( 'assets/videopress-pass.js', dirname( __DIR__ ) . '/pressshot.php' ), array(), PRESSSHOT_VERSION, array( 'in_footer' => true ) );
		}
	} else {
		return '';
	}
	$download = $shot['downloadUrl']
		? sprintf( '<a class="pressshot-share__download wp-element-button" href="%s" download>%s</a>', esc_url( pressshot_share_media_url( $shot['downloadUrl'] ) ), esc_html__( 'Download', 'pressshot' ) )
		: '';
	return pressshot_share_bar( $shot['title'], $download . pressshot_share_copy_button(), $attributes, $content, $back_url )
		. '<div class="pressshot-share__stage"><div class="pressshot-share__media">' . $media . '</div></div>';
}

/** A folder link: the top bar with its name, then its subfolders and a grid of its shots. */
function pressshot_share_folder_html( array $view, $page_url, array $attributes = array(), $content = '' ) {
	$html = pressshot_share_bar( $view['title'], pressshot_share_copy_button(), $attributes, $content ) . '<div class="pressshot-share__body">';
	if ( count( $view['folders'] ) > 1 ) {
		$html .= '<ul class="pressshot-share__folders" aria-label="' . esc_attr__( 'Subfolders', 'pressshot' ) . '">';
		foreach ( array_slice( $view['folders'], 1 ) as $folder ) {
			$html .= '<li>' . esc_html( $folder['name'] ) . '</li>';
		}
		$html .= '</ul>';
	}
	if ( ! $view['shots'] ) {
		return $html . '<p class="pressshot-share__empty">' . esc_html__( 'No shots in this folder yet.', 'pressshot' ) . '</p></div>';
	}
	$html .= '<ul class="pressshot-share__grid">';
	foreach ( $view['shots'] as $shot ) {
		$href = esc_url( add_query_arg( 'shot', $shot['id'], $page_url ) );
		if ( $shot['mediaUrl'] && 'screenshot' === $shot['kind'] ) {
			$thumb = sprintf( '<img src="%s" alt="" loading="lazy">', esc_url( pressshot_share_media_url( add_query_arg( 'size', 'medium', $shot['mediaUrl'] ) ) ) );
		} elseif ( ! empty( $shot['posterUrl'] ) ) {
			// The still sits over the Recording tile, which shows again if the still fails to load.
			$thumb = sprintf(
				'<span class="pressshot-share__recording"><span class="pressshot-share__placeholder">%s</span><img class="pressshot-share__poster" src="%s" alt="" loading="lazy" onerror="this.remove()"><span class="pressshot-share__play" aria-hidden="true"></span></span>',
				esc_html__( 'Recording', 'pressshot' ),
				esc_url( pressshot_share_media_url( $shot['posterUrl'] ) )
			);
		} else {
			$thumb = '<span class="pressshot-share__placeholder">' . esc_html__( 'Recording', 'pressshot' ) . '</span>';
		}
		$html .= sprintf( '<li><a class="pressshot-share__tile" href="%s">%s<span class="pressshot-share__shot-name">%s</span></a></li>', $href, $thumb, esc_html( $shot['title'] ) );
	}
	return $html . '</ul></div>';
}

/**
 * The share view's HTML for a token. A token that doesn't resolve, or a ?shot= outside the
 * shared tree, is the unavailable view (404).
 *
 * @param string $token      The share token.
 * @param array  $attributes Block attributes (showSiteName).
 * @param string $content    Rendered inner blocks, placed at the end of the top bar.
 */
function pressshot_share_render( $token, $attributes = array(), $content = '' ) {
	$attributes = is_array( $attributes ) ? $attributes : array();
	if ( '' === $token ) {
		return '';
	}
	$link = pressshot_link_resolve( $token );
	if ( ! $link ) {
		return pressshot_share_unavailable();
	}
	$page_url = pressshot_link_url( $token );
	$inner    = '';
	$copy_url = $page_url;
	if ( 'shot' === $link['kind'] ) {
		$inner = pressshot_share_shot_html( pressshot_public_shot( $link['target'], $token ), '', $attributes, $content );
	} elseif ( pressshot_share_requested_shot() ) {
		// One shot from a folder link: check it's in the tree, without loading the whole folder.
		$shot_id = pressshot_share_requested_shot();
		if ( ! pressshot_link_covers_shot( $link, $shot_id ) ) {
			return pressshot_share_unavailable();
		}
		$copy_url = add_query_arg( 'shot', $shot_id, $page_url );
		$inner    = pressshot_share_shot_html( pressshot_public_shot( $shot_id, $token ), $page_url, $attributes, $content );
	} else {
		$inner = pressshot_share_folder_html( pressshot_link_view( $link ), $page_url, $attributes, $content );
	}
	if ( '' === $inner ) {
		return pressshot_share_unavailable();
	}
	nocache_headers();
	return sprintf(
		'<div %s data-wp-interactive="pressshot/share-view" %s>%s</div>',
		get_block_wrapper_attributes( array( 'class' => 'folder' === $link['kind'] && ! pressshot_share_requested_shot() ? 'is-folder' : 'is-shot' ) ),
		wp_interactivity_data_wp_context( array( 'url' => $copy_url, 'copied' => false ) ),
		$inner
	);
}
