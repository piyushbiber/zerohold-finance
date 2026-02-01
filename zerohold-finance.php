<?php
/**
 * Plugin Name: ZeroHold Finance
 * Plugin URI: https://zerohold.com
 * Description: The Central Bank of ZeroHold. Provides an immutable, double-entry ledger system for Vendors, Buyers, and Admin.
 * Version: 1.0.0
 * Author: ZeroHold Team
 * Text Domain: zerohold-finance
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ZH_FINANCE_VERSION', '1.0.0' );
define( 'ZH_FINANCE_PATH', plugin_dir_path( __FILE__ ) );
define( 'ZH_FINANCE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader for Core classes
 */
spl_autoload_register( function ( $class ) {
    $prefix = 'ZeroHold\\Finance\\';
    $base_dir = ZH_FINANCE_PATH . '/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
} );

/**
 * Main Plugin Class
 */
class ZeroHold_Finance {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        register_activation_hook( __FILE__, [ $this, 'install' ] );
        add_action( 'plugins_loaded', [ $this, 'on_plugins_loaded' ] );
    }

    public function on_plugins_loaded() {
        // Initialize Core Services
        // QueryEngine is a static helper, no init needed.
        
        require_once ZH_FINANCE_PATH . 'Core/ChargeEngine.php';
        ZeroHold\Finance\Core\ChargeEngine::init();
        
        require_once ZH_FINANCE_PATH . 'Core/FinanceObserver.php';
        ZeroHold\Finance\Core\FinanceObserver::init();
        
        // Initialize Integrations
        ZeroHold\Finance\Integrations\WooCommerceListener::init();
        ZeroHold\Finance\Integrations\ZSSListener::init();
        ZeroHold\Finance\Integrations\TeraWalletListener::init();
        
        // Initialize UI Integrations
        if ( function_exists( 'dokan' ) ) {
            require_once ZH_FINANCE_PATH . 'Integrations/Dokan/DashboardIntegration.php';
            ZeroHold\Finance\Integrations\Dokan\DashboardIntegration::init();
        }
    }

    /**
     * Database Intallation
     */
    public function install() {
        require_once ZH_FINANCE_PATH . 'Core/Database.php';
        ZeroHold\Finance\Core\Database::migrate();
    }
}

ZeroHold_Finance::get_instance();
