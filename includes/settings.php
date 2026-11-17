<?php
/**
 * The PressShot settings page, and what the Mac app can read about this site's sharing.
 *
 * Right now there's one setting: whether share links open while a WordPress.com site is set to
 * Private (the pressshot_share_on_private_site option, read by pressshot_private_site_sharing_enabled()
 * in includes/private-site.php). The page shows up under Settings → PressShot for administrators,
 * and only while the site is Private, because on any other site share links already open.
 *
 * The option is registered with show_in_rest, so administrators can also read and change it at
 * /wp/v2/settings. The REST index (/wp-json/) carries a pressshot object with the site's private
 * status and whether links open on it, which the Mac app reads when it connects.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PRESSSHOT_SHARE_ON_PRIVATE_SITE_OPTION = 'pressshot_share_on_private_site';
const PRESSSHOT_SETTINGS_PAGE                = 'pressshot';

/**
 * Whether this site is set to Private on WordPress.com. WordPress.com stores that as blog_public -1;
 * core WordPress only ever uses 0 and 1. The pressshot_site_is_private filter can override it.
 */
function pressshot_site_is_private() {
	return (bool) apply_filters( 'pressshot_site_is_private', '-1' === (string) get_option( 'blog_public' ) );
}

/** True when the pressshot_share_on_private_site filter gives a different answer than the saved option. */
function pressshot_private_site_sharing_forced() {
	return (bool) get_option( PRESSSHOT_SHARE_ON_PRIVATE_SITE_OPTION, false ) !== pressshot_private_site_sharing_enabled();
}

/** Register the option for options.php and for /wp/v2/settings (administrators only, in both places). */
function pressshot_register_settings() {
	register_setting(
		PRESSSHOT_SETTINGS_PAGE,
		PRESSSHOT_SHARE_ON_PRIVATE_SITE_OPTION,
		array(
			'type'              => 'boolean',
			'description'       => __( 'Let share links open while this site is set to Private on WordPress.com.', 'pressshot' ),
			'sanitize_callback' => 'rest_sanitize_boolean',
			'show_in_rest'      => true,
			'default'           => false,
		)
	);
}
add_action( 'admin_init', 'pressshot_register_settings' );
add_action( 'rest_api_init', 'pressshot_register_settings' );

/** Add Settings → PressShot while the site is Private. Returns the page's hook name, or false. */
function pressshot_settings_add_page() {
	if ( ! pressshot_site_is_private() ) {
		return false;
	}
	return add_options_page(
		__( 'PressShot', 'pressshot' ),
		__( 'PressShot', 'pressshot' ),
		'manage_options',
		PRESSSHOT_SETTINGS_PAGE,
		'pressshot_settings_page'
	);
}
add_action( 'admin_menu', 'pressshot_settings_add_page' );

/** The Library's address. */
function pressshot_settings_library_url() {
	return home_url( '/' . pressshot_shots_path() . '/' );
}

/** Draw Settings → PressShot. */
function pressshot_settings_page() {
	if ( ! current_user_can( 'manage_options' ) || ! pressshot_site_is_private() ) {
		return;
	}
	$forced  = pressshot_private_site_sharing_forced();
	$enabled = $forced ? pressshot_private_site_sharing_enabled() : (bool) get_option( PRESSSHOT_SHARE_ON_PRIVATE_SITE_OPTION, false );
	$field   = PRESSSHOT_SHARE_ON_PRIVATE_SITE_OPTION;
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'PressShot', 'pressshot' ); ?></h1>
		<form action="options.php" method="post">
			<?php settings_fields( PRESSSHOT_SETTINGS_PAGE ); ?>
			<h2><?php esc_html_e( 'Share links on a private site', 'pressshot' ); ?></h2>
			<p><?php esc_html_e( 'This site is set to Private, so a share link shows the sign-in screen to anyone who isn\'t on the site. Turn this on and a live link opens for whoever has it. Everything else on the site stays private.', 'pressshot' ); ?></p>
			<div class="notice notice-warning inline pressshot-share-warning">
				<p>
					<strong><?php esc_html_e( 'This opens every live link at once.', 'pressshot' ); ?></strong>
					<?php
					printf(
						/* translators: %s: link to the Library. */
						esc_html__( 'Every capture gets a link when it\'s uploaded. Turn this on and all of those links open to anyone who has their address, not just the ones you meant to share. Revoke the ones you don\'t want open in the %s first.', 'pressshot' ),
						'<a href="' . esc_url( pressshot_settings_library_url() ) . '">' . esc_html__( 'Library', 'pressshot' ) . '</a>'
					);
					?>
				</p>
			</div>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Share links', 'pressshot' ); ?></th>
					<td>
						<?php if ( ! $forced ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="0">
						<?php endif; ?>
						<label for="<?php echo esc_attr( $field ); ?>">
							<input type="checkbox" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>" value="1" <?php checked( $enabled ); ?> <?php disabled( $forced ); ?>>
							<?php esc_html_e( 'Let share links open while this site is Private', 'pressshot' ); ?>
						</label>
						<?php if ( $forced ) : ?>
							<p class="description"><?php esc_html_e( 'Code on this site sets this with the pressshot_share_on_private_site filter, so this box can\'t change it.', 'pressshot' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Revoked, expired, and unknown links still see the site\'s sign-in screen.', 'pressshot' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php
			if ( ! $forced ) {
				submit_button();
			}
			?>
		</form>
	</div>
	<?php
}

/** What the Mac app reads about this site's sharing. Also used for the REST index. */
function pressshot_site_sharing_info() {
	return array(
		'privateSite'        => pressshot_site_is_private(),
		'shareOnPrivateSite' => pressshot_private_site_sharing_enabled(),
	);
}

/** Add the pressshot object to the REST index (/wp-json/), which the Mac app reads when it connects. */
add_filter(
	'rest_index',
	function ( $response ) {
		if ( $response instanceof WP_REST_Response ) {
			$data              = $response->get_data();
			$data['pressshot'] = pressshot_site_sharing_info();
			$response->set_data( $data );
		}
		return $response;
	}
);
