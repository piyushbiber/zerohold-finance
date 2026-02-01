<?php

namespace ZeroHold\Finance\Integrations\Dokan;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DashboardIntegration {

    public static function init() {
        // 1. Register Query Var
        add_filter( 'dokan_query_var_filter', [ __CLASS__, 'add_finance_endpoint' ] );

        // 2. Add Menu Item (Dokan Native Hook)
        add_filter( 'dokan_get_dashboard_nav', [ __CLASS__, 'add_finance_menu' ], 55 ); // Position 55

        // 3. Load Template (Dokan Native Loader)
        add_action( 'dokan_load_custom_template', [ __CLASS__, 'load_finance_template' ] );
        
        // 4. Enqueue Assets (if needed)
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    public static function add_finance_endpoint( $query_vars ) {
        $query_vars['zh-finance'] = 'zh-finance';
        return $query_vars;
    }

    public static function add_finance_menu( $urls ) {
        $urls['zh-finance'] = [
            'title' => __( 'Finance', 'zerohold-finance' ),
            'icon'  => '<i class="fas fa-wallet"></i>',
            'url'   => dokan_get_navigation_url( 'zh-finance' ),
            'pos'   => 55, // User requested 55
        ];
        return $urls;
    }

    public static function load_finance_template( $query_vars ) {
        if ( isset( $query_vars['zh-finance'] ) ) {
            // Load template using Dokan's standard template part loader
            // This expects the file at: [Theme]/dokan/finance/dashboard.php OR [Plugin]/Templates/finance/dashboard.php
            
            dokan_get_template_part(
                'finance/dashboard',
                '',
                [ 'zerohold-finance', ZH_FINANCE_PATH . 'Templates/' ] // Passing args or path hint? User code suggests array.
                // Note: Standard dokan_get_template_part uses 3rd arg as $args to pass to template. 
                // But Dokan might have a hook to intercept the path lookup? 
                // For now, doing exactly what user requested.
            );
            exit; // Stop further loading to prevent theme's 404 or index
        }
    }
    
    public static function enqueue_assets() {
        if ( dokan_is_seller_dashboard() && get_query_var( 'zh-finance' ) ) {
            // Check if we need to enqueue any specific JS/CSS
        }
    }
}
