<?php
/**
 * Plugin Name: PressShot
 * Plugin URI: https://github.com/dhanson-wp/pressshot-plugin
 * Description: A team Library for PressShot shots at /shots.
 * Version: 1.1.0
 * Requires at least: 7.1
 * Requires PHP: 7.4
 * Author: Derek Hanson
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pressshot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRESSSHOT_VERSION', '1.1.0' );

require_once __DIR__ . '/includes/shots.php';

require_once __DIR__ . '/includes/media.php';

require_once __DIR__ . '/includes/shots-api.php';

require_once __DIR__ . '/includes/library-trash.php';

require_once __DIR__ . '/includes/library-organize.php';

require_once __DIR__ . '/includes/folders.php';

require_once __DIR__ . '/includes/links.php';

require_once __DIR__ . '/includes/share-pages.php';

require_once __DIR__ . '/includes/share-preview.php';

require_once __DIR__ . '/includes/shots-page.php';

require_once __DIR__ . '/includes/public-surface.php';

require_once __DIR__ . '/includes/private-site.php';

require_once __DIR__ . '/includes/settings.php';

register_activation_hook( __FILE__, 'pressshot_share_activate' );

register_deactivation_hook( __FILE__, 'pressshot_share_deactivate' );
