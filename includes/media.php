<?php
/**
 * Streams a shot's file with byte-range support.
 *
 * This file only streams. It never checks who is asking: the route that calls
 * pressshot_stream_file() decides that, and decides what to do with a trashed shot.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Holds an already-open stream without exposing a server filesystem path. */
class PressShot_Media_Response extends WP_REST_Response {
	private $stream;
	private $length;
	/** The most seconds emit() may run, or 0 for no limit. See limit_time(). */
	private $time_limit = 0;

	/**
	 * @param string|false $download The download file name, or false to show the file inline.
	 */
	public function __construct( $stream, $mime, $size, $start, $end, $partial, $download ) {
		$this->stream = $stream;
		$this->length = $end - $start + 1;
		fseek( $stream, $start );
		$headers = array(
			'Content-Type'            => $mime,
			'Content-Length'          => (string) $this->length,
			'Accept-Ranges'           => 'bytes',
			'Cache-Control'           => 'private, no-store, no-transform',
			'X-Content-Type-Options'  => 'nosniff',
			'Referrer-Policy'         => 'no-referrer',
			'Content-Security-Policy' => "default-src 'none'; sandbox",
		);
		if ( $partial ) {
			$headers['Content-Range'] = "bytes $start-$end/$size";
		}
		if ( $download ) {
			$headers['Content-Disposition'] = 'attachment; filename="' . $download . '"';
		}
		parent::__construct( null, $partial ? 206 : 200, $headers );
	}

	/**
	 * Caps how long emit() may run, in wall-clock seconds, so one slow client can't hold a PHP worker
	 * for as long as it likes. A response that hits the cap stops short and the client sees a
	 * truncated body. 0 (the default) means no cap.
	 */
	public function limit_time( $seconds ) {
		$this->time_limit = max( 0, (int) $seconds );
		return $this;
	}

	public function time_limit() {
		return $this->time_limit;
	}

	/** The clock emit() checks against its time limit. Tests override it. */
	protected function now() {
		return microtime( true );
	}

	public function emit( $method ) {
		if ( 'HEAD' === $method ) {
			return;
		}
		$deadline = $this->time_limit > 0 ? $this->now() + $this->time_limit : 0;
		$left     = $this->length;
		while ( $left > 0 && ! feof( $this->stream ) ) {
			// Wall-clock, not PHP's time limit: on most hosts that counts CPU time, not time spent waiting on a slow client.
			if ( $deadline && $this->now() >= $deadline ) {
				break;
			}
			$chunk = fread( $this->stream, min( 65536, $left ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw file bytes.
			$left -= strlen( $chunk );
			// Send each chunk on, so a long recording never sits whole in memory or a buffer.
			flush();
			if ( connection_aborted() ) {
				break;
			}
		}
	}

	/**
	 * Gets PHP ready to stream the file: drops output buffers (so the file isn't held in memory and
	 * nothing stray is prepended) and sets PHP's own time limit where the host allows it. A response
	 * with no cap (a long recording) lifts the limit; a capped one gets the cap plus a few seconds,
	 * as a backstop to the wall-clock check in emit(). The serve filter calls this right before
	 * emit(); tests call emit() alone and keep their own buffer.
	 */
	public function prepare_output() {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $this->time_limit > 0 ? $this->time_limit + 5 : 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Some hosts disable it.
		}
	}

	public function __destruct() {
		if ( is_resource( $this->stream ) ) {
			fclose( $this->stream );
		}
	}
}

/** A shot's VideoPress guid: the post meta, else the guid VideoPress stores in the attachment metadata, else null. */
function pressshot_videopress_guid( $id ) {
	$guid = get_post_meta( $id, 'videopress_guid', true );
	if ( ! $guid ) {
		$meta = wp_get_attachment_metadata( $id );
		$guid = is_array( $meta ) && isset( $meta['videopress']['guid'] ) ? $meta['videopress']['guid'] : '';
	}
	return $guid ? (string) $guid : null;
}

/** How long a minted viewing pass is reused for the same video, in seconds, at most. */
const PRESSSHOT_VIDEOPRESS_PASS_REUSE = 300;

/** A cached pass is dropped this many seconds before it expires, so the one handed out still has time to load. */
const PRESSSHOT_VIDEOPRESS_PASS_MARGIN = 120;

/**
 * A short-lived VideoPress viewing pass for one video, or null. Private VideoPress videos (every video
 * on a private site) only play with one. Jetpack mints it in the site's name, so callers must decide
 * first that the viewer may watch: the Library checks site access, share pages check the link.
 * The pressshot_videopress_pass filter can supply one (tests, or sites without Jetpack).
 *
 * Minting asks WordPress.com over the network, so a pass is reused for the same video for a few
 * minutes (see pressshot_videopress_pass_ttl) instead of minted for every poster and player view.
 * The cache sits behind the callers' checks, so a revoked or expired link still gets no pass.
 */
function pressshot_videopress_pass( $guid ) {
	if ( ! is_string( $guid ) || 1 !== preg_match( '/^[A-Za-z0-9]{8}$/', $guid ) ) {
		return null;
	}
	$key    = 'pressshot_vp_pass_' . $guid;
	$cached = get_transient( $key );
	if ( is_array( $cached ) && isset( $cached['pass'], $cached['until'] ) && is_string( $cached['pass'] ) && time() < (int) $cached['until'] ) {
		return $cached['pass'];
	}
	$pass = apply_filters( 'pressshot_videopress_pass', null, $guid );
	if ( null === $pass && class_exists( 'Automattic\\Jetpack\\VideoPress\\VideoPressToken' ) ) {
		try {
			$pass = \Automattic\Jetpack\VideoPress\VideoPressToken::videopress_playback_jwt( $guid );
		} catch ( \Throwable $e ) {
			$pass = null;
		}
	}
	if ( ! is_string( $pass ) || '' === $pass ) {
		return null;
	}
	$ttl = pressshot_videopress_pass_ttl( $pass );
	if ( $ttl > 0 ) {
		set_transient( $key, array( 'pass' => $pass, 'until' => time() + $ttl ), $ttl );
	}
	return $pass;
}

/**
 * How long a freshly minted pass may be reused, in seconds: PRESSSHOT_VIDEOPRESS_PASS_REUSE at most,
 * and never past PRESSSHOT_VIDEOPRESS_PASS_MARGIN before the pass's own expiry when it is a JWT that
 * says when it expires. 0 means don't reuse it.
 */
function pressshot_videopress_pass_ttl( $pass ) {
	$ttl   = PRESSSHOT_VIDEOPRESS_PASS_REUSE;
	$parts = explode( '.', $pass );
	if ( 3 === count( $parts ) ) {
		$claims = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
		if ( is_array( $claims ) && isset( $claims['exp'] ) && is_numeric( $claims['exp'] ) ) {
			$ttl = min( $ttl, (int) $claims['exp'] - time() - PRESSSHOT_VIDEOPRESS_PASS_MARGIN );
		}
	}
	return max( 0, $ttl );
}

/** Drops a video's cached viewing pass, so the next request mints a new one. */
function pressshot_videopress_pass_forget( $guid ) {
	if ( is_string( $guid ) && 1 === preg_match( '/^[A-Za-z0-9]{8}$/', $guid ) ) {
		delete_transient( 'pressshot_vp_pass_' . $guid );
	}
}

/**
 * The still VideoPress made for a video, or null: the 720p frame when there is one (the full poster
 * is as big as the video), else the poster. Only VideoPress's own https hosts count, since the
 * poster route redirects there. Private videos need a viewing pass to load it; see the poster route.
 */
function pressshot_videopress_poster( $id ) {
	$meta = wp_get_attachment_metadata( $id );
	$vp   = is_array( $meta ) && isset( $meta['videopress'] ) && is_array( $meta['videopress'] ) ? $meta['videopress'] : array();
	$base = isset( $vp['file_url_base']['https'] ) ? (string) $vp['file_url_base']['https'] : '';
	$file = isset( $vp['files']['hd']['original_img'] ) ? (string) $vp['files']['hd']['original_img'] : '';
	$url  = $base && $file ? trailingslashit( $base ) . rawurlencode( $file ) : ( isset( $vp['poster'] ) ? (string) $vp['poster'] : '' );
	$host = wp_parse_url( $url, PHP_URL_HOST );
	return 0 === strpos( $url, 'https://' ) && in_array( $host, array( 'videos.files.wordpress.com', 'videos.videopress.com' ), true ) ? $url : null;
}

/**
 * A redirect to a recording's still, signed with a fresh viewing pass when the site can mint one.
 * Callers decide first that the viewer may see it (Library access, or a live share link).
 */
function pressshot_poster_redirect( $guid, $poster ) {
	$pass     = pressshot_videopress_pass( $guid );
	$response = new WP_REST_Response( null, 302 );
	$response->header( 'Location', $pass ? add_query_arg( 'metadata_token', rawurlencode( $pass ), $poster ) : $poster );
	$response->header( 'Cache-Control', 'private, no-store' );
	return $response;
}

/** True for a video that lives only on VideoPress: it has no local file to stream. */
function pressshot_is_videopress_only( $id ) {
	if ( 0 !== strpos( (string) get_post_mime_type( $id ), 'video/' ) ) {
		return false;
	}
	$meta = wp_get_attachment_metadata( $id );
	return (bool) ( get_post_meta( $id, 'videopress_guid', true ) || ! empty( $meta['videopress'] ) );
}

/**
 * Opens a shot's local file and returns a streaming response, or a WP_Error.
 * A VideoPress-only video (no local file) is never streamed from anywhere else; it is a 404 here.
 */
function pressshot_stream_file( $attachment_id, WP_REST_Request $request, $size = '' ) {
	$id        = (int) $attachment_id;
	$not_found = function () {
		return new WP_Error( 'pressshot_not_found', 'Media unavailable.', array( 'status' => 404 ) );
	};
	$mime      = get_post_mime_type( $id );
	if ( pressshot_is_videopress_only( $id ) ) {
		return $not_found();
	}
	$base    = realpath( wp_get_upload_dir()['basedir'] );
	$file    = get_attached_file( $id );
	$allowed = array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif', 'video/mp4', 'video/quicktime' );
	if ( 'medium' === $size && $file && 0 === strpos( (string) $mime, 'image/' ) && in_array( $mime, $allowed, true ) ) {
		// Prefer the generated medium file when it sits beside the original. It then goes through the same checks below.
		$meta   = wp_get_attachment_metadata( $id );
		$medium = isset( $meta['sizes']['medium']['file'] ) ? wp_basename( (string) $meta['sizes']['medium']['file'] ) : '';
		if ( '' !== $medium && is_file( dirname( $file ) . '/' . $medium ) ) {
			$file       = dirname( $file ) . '/' . $medium;
			$size_mime  = isset( $meta['sizes']['medium']['mime-type'] ) ? (string) $meta['sizes']['medium']['mime-type'] : '';
			$mime       = in_array( $size_mime, $allowed, true ) ? $size_mime : $mime;
		}
	}
	$path = $file ? realpath( $file ) : false;
	if ( ! $base || ! $path || 0 !== strpos( $path, $base . DIRECTORY_SEPARATOR ) || ! is_file( $path ) || ! in_array( $mime, $allowed, true ) ) {
		return $not_found();
	}
	$stream = @fopen( $path, 'rb' );
	$stat   = $stream ? fstat( $stream ) : false;
	if ( ! $stat || $stat['size'] < 1 ) {
		if ( $stream ) {
			fclose( $stream );
		}
		return $not_found();
	}
	$size  = $stat['size'];
	$start = 0;
	$end   = $size - 1;
	$range = $request->get_header( 'range' );
	if ( $range ) {
		$bad = new WP_Error( 'invalid_range', 'Invalid range.', array( 'status' => 416 ) );
		if ( ! preg_match( '/^bytes=([0-9]*)-([0-9]*)$/D', $range, $m ) || ( '' === $m[1] && '' === $m[2] ) || strlen( $m[1] ) > 15 || strlen( $m[2] ) > 15 ) {
			fclose( $stream );
			return $bad;
		}
		if ( '' === $m[1] ) {
			$start = max( 0, $size - (int) $m[2] );
		} else {
			$start = (int) $m[1];
			if ( '' !== $m[2] ) {
				$end = min( $end, (int) $m[2] );
			}
		}
		if ( $start >= $size || $end < $start ) {
			fclose( $stream );
			return $bad;
		}
	}
	// Downloads keep the file's extension so the saved file opens: PressShot-{id}.{ext}.
	$extension = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', (string) pathinfo( $path, PATHINFO_EXTENSION ) ) );
	$download  = '1' === $request->get_param( 'download' ) ? 'PressShot-' . $id . ( '' !== $extension ? '.' . $extension : '' ) : false;
	return new PressShot_Media_Response( $stream, $mime, $size, $start, $end, (bool) $range, $download );
}

add_filter(
	'rest_pre_serve_request',
	function ( $served, $response, $request ) {
		if ( ! $served && $response instanceof PressShot_Media_Response ) {
			$response->prepare_output();
			$response->emit( $request->get_method() );
			return true;
		}
		return $served;
	},
	10,
	3
);
