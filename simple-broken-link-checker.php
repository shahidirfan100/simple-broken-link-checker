<?php
/**
 * Plugin Name:       Simple Broken Link Checker
 * Plugin URI:        https://wordpress.org/plugins/simple-broken-link-checker/
 * Description:       Find and review broken links, redirects, and missing images locally with clear evidence and safe repairs.
 * Version:           1.0.7
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Shahid Irfan
 * Author URI:        https://profiles.wordpress.org/shahidirfan100/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       simple-broken-link-checker
 * Domain Path:       /languages
 *
 * @package SimpleBrokenLinkChecker
 */

defined( 'ABSPATH' ) || exit;

define( 'SBLC_VERSION', '1.0.7' );
define( 'SBLC_PLUGIN_FILE', __FILE__ );
define( 'SBLC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SBLC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SBLC_PLUGIN_DIR . 'includes/class-settings.php';
require_once SBLC_PLUGIN_DIR . 'includes/class-installer.php';
require_once SBLC_PLUGIN_DIR . 'includes/class-database.php';
require_once SBLC_PLUGIN_DIR . 'includes/class-url.php';
require_once SBLC_PLUGIN_DIR . 'includes/class-ssrf-guard.php';
require_once SBLC_PLUGIN_DIR . 'includes/class-http-checker.php';
require_once SBLC_PLUGIN_DIR . 'includes/class-extractor.php';
require_once SBLC_PLUGIN_DIR . 'includes/class-sources.php';
require_once SBLC_PLUGIN_DIR . 'includes/class-scanner.php';
require_once SBLC_PLUGIN_DIR . 'includes/class-repair.php';
require_once SBLC_PLUGIN_DIR . 'includes/class-rest.php';
require_once SBLC_PLUGIN_DIR . 'admin/class-admin.php';
require_once SBLC_PLUGIN_DIR . 'includes/class-plugin.php';

// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- The callback registers one documented 60-second continuation interval for bounded background work.
add_filter( 'cron_schedules', array( 'SimpleBrokenLinkChecker\\Installer', 'cron_schedules' ) );
register_activation_hook( SBLC_PLUGIN_FILE, array( 'SimpleBrokenLinkChecker\\Installer', 'activate' ) );
register_deactivation_hook( SBLC_PLUGIN_FILE, array( 'SimpleBrokenLinkChecker\\Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\SimpleBrokenLinkChecker\Plugin::instance()->run();
	}
);
