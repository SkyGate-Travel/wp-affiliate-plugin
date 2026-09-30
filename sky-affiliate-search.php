<?php
/**
 * Plugin Name:       Sky Affiliate Search
 * Plugin URI:        https://github.com/nextpay-ir/sky-wp
 * Description:       Adds a travel search page (flights, hotels, activities, tours) to any WordPress site. Visitors search and filter live inventory from a Sky platform, then follow an affiliate-tagged link to the platform to book.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NextPay
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sky-affiliate-search
 * Domain Path:       /languages
 *
 * @package SkyAffiliateSearch
 */

defined( 'ABSPATH' ) || exit;

define( 'SKY_AFF_VERSION', '1.2.0' );
define( 'SKY_AFF_FILE', __FILE__ );
define( 'SKY_AFF_PATH', plugin_dir_path( __FILE__ ) );
define( 'SKY_AFF_URL', plugin_dir_url( __FILE__ ) );

/**
 * Option key holding every setting as one array. One option keeps the
 * autoloaded-option count down and lets the settings API validate the whole
 * form in a single sanitize callback.
 */
define( 'SKY_AFF_OPTION', 'sky_aff_settings' );

/** REST namespace the front-end talks to. Never the Sky API directly. */
define( 'SKY_AFF_REST_NS', 'sky-affiliate/v1' );

require_once SKY_AFF_PATH . 'includes/class-sky-aff-settings.php';
require_once SKY_AFF_PATH . 'includes/class-sky-aff-api-client.php';
require_once SKY_AFF_PATH . 'includes/class-sky-aff-links.php';
require_once SKY_AFF_PATH . 'includes/class-sky-aff-stream.php';
require_once SKY_AFF_PATH . 'includes/class-sky-aff-rest-controller.php';
require_once SKY_AFF_PATH . 'includes/class-sky-aff-shortcode.php';
require_once SKY_AFF_PATH . 'includes/class-sky-aff-plugin.php';

/**
 * Boot the plugin.
 *
 * @return Sky_Aff_Plugin
 */
function sky_aff() {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new Sky_Aff_Plugin();
	}

	return $plugin;
}

add_action( 'plugins_loaded', array( sky_aff(), 'boot' ) );

/**
 * Create the search page on activation so the site owner has somewhere to
 * point visitors at without having to know the shortcode.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Sky_Aff_Plugin::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		Sky_Aff_Plugin::deactivate();
	}
);
