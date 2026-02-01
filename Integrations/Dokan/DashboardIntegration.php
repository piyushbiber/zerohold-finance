<?php

namespace ZeroHold\Finance\Integrations\Dokan;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DashboardIntegration {

    public static function init() {
        // 1. Register Query Var
        add_filter( 'dokan_query_var_filter', [ __CLASS__, 'add_finance_endpoint' ] );

        // 2. Add Menu Item
        add_filter( 'dokan_get_dashboard_nav', [ __CLASS__, 'add_finance_menu' ], 50 ); // Position after Orders

        // 3. Load Template
        add_action( 'dokan_load_custom_template', [ __CLASS__, 'load_finance_template' ] );
        
        // 4. Register Scripts (for UI)
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    public static function add_finance_endpoint( $query_vars ) {
        $query_vars['zh-finance'] = 'zh-finance';
        return $query_vars;
    }

    public static function add_finance_menu( $urls ) {
        $urls['zh-finance'] = [
            'title'      => __( 'Finance', 'zerohold-finance' ),
            'icon'       => '<i class="fas fa-wallet"></i>',
            'url'        => dokan_get_navigation_url( 'zh-finance' ),
            'pos'        => 35, // After Orders (30)
            'permission' => 'dokan_view_overview_menu' // Standard permission
        ];
        return $urls;
    }

    public static function load_finance_template( $query_vars ) {
        if ( isset( $query_vars['zh-finance'] ) ) {
            // Load our custom template
            $template_path = ZH_FINANCE_PATH . 'Templates/dokan-finance-dashboard.php';
            
            if ( file_exists( $template_path ) ) {
                load_template( $template_path );
            } else {
                echo '<div class="dokan-error">Finance Template Not Found</div>';
            }
        }
    }
    
    public static function enqueue_assets() {
        if ( dokan_is_seller_dashboard() && get_query_var( 'zh-finance' ) ) {
            // Check if we need to enqueue any specific JS/CSS
            // For now, we rely on inline styles in the template or global Dokan styles
        }
    }
}
