<?php

class Dxw3_Passeli_Merit {

	protected $loader;
	protected $plugin_name;
	protected $version;
	protected $api_id;
	protected $api_key;

	public function __construct() {
		if ( defined( 'DXW3_PASSELI_MERIT_VERSION' ) ) {
			$this->version = DXW3_PASSELI_MERIT_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'dxw3-passeli-merit';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	private function load_dependencies() {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-dxw3-passeli-merit-loader.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-dxw3-passeli-merit-i18n.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-dxw3-passeli-merit-admin.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-dxw3-passeli-merit-public.php';
		$this->loader = new Dxw3_Passeli_Merit_Loader();
	}

	private function set_locale() {
		$plugin_i18n = new Dxw3_Passeli_Merit_i18n();
		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
	}

	// The main hooks of the plugins defined
	private function define_admin_hooks() {
		$plugin_admin = new Dxw3_Passeli_Merit_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

		$this->loader->add_action( 'admin_menu', $plugin_admin, 'dxw3_pm_menu', 99 );									// Add settings page for API id and key 
		$this->loader->add_filter( 'cron_schedules', $plugin_admin,'dxw3_cron_schedules' );								// Paid invoices need to be polled with cron runs
		$this->loader->add_action( 'woocommerce_admin_order_data_after_billing_address', $plugin_admin, 'dxw3_pm_show_order_id_admin', 10, 1 );  // Admin to show invoice status and id
		$this->loader->add_action( 'woocommerce_order_status_processing', $plugin_admin, 'dxw3_pm_order_to_processing', 20, 2 );		// Create invoice when order goes to processing
		$this->loader->add_action( 'woocommerce_order_status_changed', $plugin_admin, 'dxw3_cancelled_order_email_to_accounting', 10, 4 ); // Add notification to accounting on cancellation
		$this->loader->add_filter( 'woocommerce_email_headers', $plugin_admin, 'dxw3_refunded_order_email_to_accounting', 9999, 3 ); // Add notification to accounting on refund
	}

	private function define_public_hooks() {
		$plugin_public = new Dxw3_Passeli_Merit_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );
	}

	public function run() {
		$this->loader->run();
	}

	public function get_plugin_name() {
		return $this->plugin_name;
	}

	public function get_loader() {
		return $this->loader;
	}

	public function get_version() {
		return $this->version;
	}

}
