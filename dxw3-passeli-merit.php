<?php
/**
 * Plugin Name:       dxw3 Passeli Merit
 * Plugin URI:        https://dx-w3.com/plugins/
 * Description:       WooCommerce integration with Passeli Merit Accounting pbl.
 * Version:           1.1.1
 * Author:            dxw3
 * Author URI:        https://dx-w3.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       dxw3-passeli-merit
 * Domain Path:       /languages
 */
if ( ! defined( 'WPINC' ) ) { die; }
define( 'DXW3_PASSELI_MERIT_VERSION', '1.1.1' );

function activate_dxw3_passeli_merit() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-dxw3-passeli-merit-activator.php';
	Dxw3_Passeli_Merit_Activator::activate();
}

function deactivate_dxw3_passeli_merit() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-dxw3-passeli-merit-deactivator.php';
	Dxw3_Passeli_Merit_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_dxw3_passeli_merit' );
register_deactivation_hook( __FILE__, 'deactivate_dxw3_passeli_merit' );

// Require the main class of the plugin for including files and defining the hooks.
require plugin_dir_path( __FILE__ ) . 'includes/class-dxw3-passeli-merit.php';

function run_dxw3_passeli_merit() {
	$plugin = new Dxw3_Passeli_Merit();
	$plugin->run();
}
run_dxw3_passeli_merit();
