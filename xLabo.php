<?php
/**
 * Plugin Name: xLabo
 * Plugin URI: https://github.com/KantanPro/xLabo
 * Description: WordPress の投稿を公開時に X（旧 Twitter）へ自動シェアするプラグインです。
 * Version: 1.1.0
 * Author: KantanPro
 * License: GPL-2.0-or-later
 * Text Domain: xlabo
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'XLABO_VERSION', '1.1.0' );
define( 'XLABO_PLUGIN_FILE', __FILE__ );
define( 'XLABO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'XLABO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once XLABO_PLUGIN_DIR . 'includes/class-xlabo-api-client.php';
require_once XLABO_PLUGIN_DIR . 'includes/class-xlabo-oauth.php';
require_once XLABO_PLUGIN_DIR . 'includes/class-xlabo-auto-poster.php';
require_once XLABO_PLUGIN_DIR . 'includes/class-xlabo-twitter-cards.php';
require_once XLABO_PLUGIN_DIR . 'includes/class-xlabo-settings.php';
require_once XLABO_PLUGIN_DIR . 'includes/class-xlabo.php';

/**
 * プラグインを起動する。
 *
 * @return XLabo_Plugin
 */
function xlabo(): XLabo_Plugin {
	return XLabo_Plugin::instance();
}

register_activation_hook( __FILE__, array( 'XLabo_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'XLabo_Plugin', 'deactivate' ) );

xlabo();
