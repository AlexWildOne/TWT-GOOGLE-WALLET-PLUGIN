<?php
/**
 * Plugin Name: NFC Wallet Plugin - Google Wallet
 * Description: Criação e gestão de cartões Google Wallet, sem dependências externas.
 * Version: 3.1.0
 * Author: The Wild Theory
 * Text Domain: nfc-wallet-plugin
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'TWTW_PLUGIN_FILE', __FILE__ );
define( 'TWTW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TWTW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TWTW_TEXTDOMAIN', 'nfc-wallet-plugin' );

require_once TWTW_PLUGIN_DIR . 'includes/class-twtw-helpers.php';
require_once TWTW_PLUGIN_DIR . 'includes/class-twtw-admin.php';
require_once TWTW_PLUGIN_DIR . 'includes/class-twtw-shortcodes.php';

add_action( 'plugins_loaded', function() {
	load_plugin_textdomain( TWTW_TEXTDOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Admin
	if ( is_admin() ) {
		TWTW_Admin::instance();
	}

	// Front
	TWTW_Shortcodes::instance();
}, 5 );
