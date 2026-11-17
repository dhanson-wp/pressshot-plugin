<?php
/**
 * Settings → PressShot: the "Let share links open while this site is Private" toggle, its option,
 * and the pressshot object on the REST index that the Mac app reads.
 */
require __DIR__ . '/harness.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';

$option      = 'pressshot_share_on_private_site';
$had_option  = false !== get_option( $option, false );
$saved_value = get_option( $option, null );
register_shutdown_function(
	function () use ( $option, $had_option, $saved_value ) {
		if ( $had_option ) {
			update_option( $option, $saved_value );
		} else {
			delete_option( $option );
		}
	}
);
delete_option( $option );
remove_all_filters( 'pressshot_share_on_private_site' );

$private = function ( $on ) {
	remove_all_filters( 'pressshot_site_is_private' );
	add_filter( 'pressshot_site_is_private', $on ? '__return_true' : '__return_false' );
};
$index   = function () use ( $call ) {
	$data = $call( 'GET', '/' )->get_data();
	return isset( $data['pressshot'] ) ? $data['pressshot'] : null;
};
$page    = function () {
	ob_start();
	pressshot_settings_page();
	return (string) ob_get_clean();
};
$menu    = function () {
	global $submenu, $_registered_pages, $_parent_pages;
	$submenu           = array();
	$_registered_pages = array();
	$_parent_pages     = array();
	pressshot_settings_add_page();
	$slugs = array();
	foreach ( isset( $submenu['options-general.php'] ) ? $submenu['options-general.php'] : array() as $item ) {
		$slugs[] = $item[2];
	}
	return in_array( 'pressshot', $slugs, true );
};

$admin  = $make_user( 'administrator' );
$editor = $make_user( 'editor' );
$author = $make_user( 'author' );

// Private status: WordPress.com stores Private as blog_public -1.
remove_all_filters( 'pressshot_site_is_private' );
$check( 'private status follows blog_public', ( '-1' === (string) get_option( 'blog_public' ) ) === pressshot_site_is_private() );
$private( true );
$check( 'the private status filter can mark a site Private', pressshot_site_is_private() );

// The option and the filter.
$check( 'sharing on a private site is off by default', false === pressshot_private_site_sharing_enabled() );
update_option( $option, true );
$check( 'turning the option on turns sharing on', true === pressshot_private_site_sharing_enabled() );
$check( 'with no filter, the option isn\'t forced', false === pressshot_private_site_sharing_forced() );
add_filter( 'pressshot_share_on_private_site', '__return_false' );
$check( 'the filter wins over the option (off)', false === pressshot_private_site_sharing_enabled() );
$check( 'a filter that disagrees with the option counts as forced', true === pressshot_private_site_sharing_forced() );
remove_all_filters( 'pressshot_share_on_private_site' );
update_option( $option, false );
add_filter( 'pressshot_share_on_private_site', '__return_true' );
$check( 'the filter wins over the option (on)', true === pressshot_private_site_sharing_enabled() );
remove_all_filters( 'pressshot_share_on_private_site' );

// The registered setting sanitizes to a boolean.
pressshot_register_settings();
update_option( $option, 'yes' );
$check( 'a saved "yes" reads as on', true === pressshot_private_site_sharing_enabled() );
update_option( $option, '0' );
$check( 'a saved "0" reads as off', false === pressshot_private_site_sharing_enabled() );
update_option( $option, '1' );
$check( 'wp option update ... 1 turns it on', true === pressshot_private_site_sharing_enabled() );
update_option( $option, false );

// /wp/v2/settings: administrators only.
wp_set_current_user( $admin );
$settings = $call( 'GET', '/wp/v2/settings' );
$check( 'administrators see the option in /wp/v2/settings', 200 === $settings->get_status() && array_key_exists( $option, $settings->get_data() ) && false === $settings->get_data()[ $option ] );
$updated = $call( 'POST', '/wp/v2/settings', array( $option => true ) );
$check( 'administrators can turn it on through /wp/v2/settings', 200 === $updated->get_status() && true === pressshot_private_site_sharing_enabled() );
update_option( $option, false );
wp_set_current_user( $editor );
$check( 'editors can\'t change it through /wp/v2/settings', 403 === $call( 'POST', '/wp/v2/settings', array( $option => true ) )->get_status() && false === pressshot_private_site_sharing_enabled() );
wp_set_current_user( $author );
$check( 'authors can\'t read /wp/v2/settings', 403 === $call( 'GET', '/wp/v2/settings' )->get_status() );

// The REST index, which the Mac app reads.
wp_set_current_user( 0 );
$private( true );
$info = $index();
$check( 'the REST index carries the pressshot object', is_array( $info ) );
$check( 'the REST index says the site is Private', true === $info['privateSite'] );
$check( 'the REST index says sharing is off', false === $info['shareOnPrivateSite'] );
update_option( $option, true );
$check( 'the REST index says sharing is on once it is', true === $index()['shareOnPrivateSite'] );
add_filter( 'pressshot_share_on_private_site', '__return_false' );
$check( 'the REST index follows the filter over the option', false === $index()['shareOnPrivateSite'] );
remove_all_filters( 'pressshot_share_on_private_site' );
$private( false );
$check( 'the REST index says a public site isn\'t Private', false === $index()['privateSite'] );
update_option( $option, false );

// Settings → PressShot shows up only for administrators, and only while the site is Private.
wp_set_current_user( $admin );
$private( true );
$check( 'administrators get Settings → PressShot on a private site', $menu() );
$private( false );
$check( 'no Settings → PressShot when the site isn\'t Private', ! $menu() );
$check( 'the page draws nothing when the site isn\'t Private', '' === trim( $page() ) );
$private( true );
wp_set_current_user( $editor );
$check( 'editors don\'t get Settings → PressShot', ! $menu() );
$check( 'the page draws nothing for editors', '' === trim( $page() ) );

// The page itself.
wp_set_current_user( $admin );
$html = $page();
$check( 'the page has the toggle label', false !== strpos( $html, 'Let share links open while this site is Private' ) );
$check( 'the toggle posts the option', false !== strpos( $html, 'name="' . $option . '" value="1"' ) );
$check( 'the toggle is off when the option is off', false === strpos( $html, "checked='checked'" ) );
$check( 'the page warns that every live link opens at once', false !== strpos( $html, 'This opens every live link at once.' ) );
$check( 'the warning links to the Library', false !== strpos( $html, 'href="' . esc_url( home_url( '/' . pressshot_shots_path() . '/' ) ) . '"' ) );
$check( 'the page posts to options.php with a nonce', false !== strpos( $html, 'action="options.php"' ) && false !== strpos( $html, 'name="_wpnonce"' ) );
$check( 'the page copy has no em dashes', false === strpos( $html, "\u{2014}" ) );
update_option( $option, true );
$check( 'the toggle is on when the option is on', false !== strpos( $page(), "checked='checked'" ) );
update_option( $option, false );
add_filter( 'pressshot_share_on_private_site', '__return_true' );
$forced = $page();
$check( 'a filter-set value shows as on', false !== strpos( $forced, "checked='checked'" ) );
$check( 'a filter-set value disables the toggle', false !== strpos( $forced, "disabled='disabled'" ) );
$check( 'a filter-set value says why', false !== strpos( $forced, 'pressshot_share_on_private_site filter' ) );
$check( 'a filter-set value has no Save button', false === strpos( $forced, 'type="submit"' ) );
remove_all_filters( 'pressshot_share_on_private_site' );
remove_all_filters( 'pressshot_site_is_private' );
wp_set_current_user( 0 );
