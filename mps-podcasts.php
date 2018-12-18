<?php
/**
 * Plugin Name: Media Portfolios Podcasting
 * Version: 1.0.0
 * Plugin URI: https://github.com/MediaPortfolios/mps-podcasts
 * Description: Podcasting plugin.
 * Author: Media Portfolios
 * Author URI: https://mediaportfolios.com/
 * Requires PHP: 5.3.3
 * Requires at least: 4.4
 * Tested up to: 4.9.8
 *
 * Text Domain: mps-podcasts
 *
 * @package Media_Portfolios_Podcasting
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '5.3.3', '<' ) ) { // PHP 5.3.3 or greater
	/**
	 * We are running under PHP 5.3.3
	 * Display an admin notice and gracefully do nothing.
	 */
	is_admin() && add_action( 'admin_notices', create_function( '', "
	echo '
		<div class=\"error\">
			<p>
				<strong>The Media Portfolios Podcasting plugin requires PHP version 5.3.3 or later. Please contact your web host to upgrade your PHP version or deactivate the plugin.</strong>.
			</p>
			<p>We apologise for any inconvenience.</p>
		</div>
	';"
	) );

	return;
}

define( 'MPP_VERSION', '1.19.15' );
define( 'MPP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MPP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

if ( ! defined( 'MPP_PODMOTOR_APP_URL' ) ) {
	define( 'MPP_PODMOTOR_APP_URL', 'https://app.castos.com/' );
}
if ( ! defined( 'MPP_PODMOTOR_EPISODES_URL' ) ) {
	define( 'MPP_PODMOTOR_EPISODES_URL', 'https://episodes.castos.com/' );
}

define( 'MPP_LOG_DIR_PATH', MPP_PLUGIN_PATH . 'log' . DIRECTORY_SEPARATOR );
define( 'MPP_LOG_DIR_URL', MPP_PLUGIN_URL . 'log' . DIRECTORY_SEPARATOR );
define( 'MPP_LOG_PATH', MPP_LOG_DIR_PATH . 'ssp.log.' . date( 'd-m-y' ) . '.txt' );
define( 'MPP_LOG_URL', MPP_LOG_DIR_URL . 'ssp.log.' . date( 'd-m-y' ) . '.txt' );

require_once 'includes/ssp-functions.php';
require_once 'includes/class-ssp-admin.php';
require_once 'includes/class-ssp-frontend.php';
require_once 'includes/class-podmotor-handler.php';

/**
 * Only require the REST API endpoints if the user is using WordPress greater than 4.7
 */
global $wp_version;
if ( version_compare( $wp_version, '4.7', '>=' ) ) {
	require_once 'includes/class-ssp-wp-rest-api.php';
	require_once 'includes/class-ssp-wp-rest-episodes-controller.php';
}

global $ssp_admin, $ss_podcasting, $ssp_wp_rest_api;
$ssp_admin       = new MPP_Admin( __FILE__, MPP_VERSION );
$ss_podcasting   = new MPP_Frontend( __FILE__, MPP_VERSION );
$ssp_wp_rest_api = new MPP_WP_REST_API( MPP_VERSION );

if ( is_admin() ) {
	global $ssp_settings;
	require_once( 'includes/class-ssp-settings.php' );
	$ssp_settings = new MPP_Settings( __FILE__, MPP_VERSION );
}
