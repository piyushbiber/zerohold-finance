<?php

namespace ZeroHold\Finance\Integrations\Dokan;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DashboardIntegration {

    public static function init() {
        // 1. Register Query Var (CRITICAL FIX)
        add_filter( 'dokan_dashboard_shortcode_query_vars', [ __CLASS__, 'register_query_var' ] );

        // 2. Add Menu Item (Dokan Native Hook)
        add_filter( 'dokan_get_dashboard_nav', [ __CLASS__, 'add_finance_menu' ], 90 );

        // 3. Load Template (Dokan Native Loader)
        add_action( 'dokan_load_custom_template', [ __CLASS__, 'load_finance_template' ] );
        
        // 4. Register URL Endpoint (Rewrites)
        add_filter( 'dokan_query_var_filter', [ __CLASS__, 'add_finance_endpoint' ] );
    }

    public static function register_query_var( $query_vars ) {
        $query_vars['zh-finance'] = true;
        return $query_vars;
    }

    public static function add_finance_menu( $urls ) {
        $urls['zh-finance'] = [
            'title' => __( 'Finance', 'zerohold-finance' ),
            'icon'  => '<i class="fas fa-wallet"></i>',
            'url'   => dokan_get_navigation_url( 'zh-finance' ),
            'pos'   => 90,
        ];
        return $urls;
    }

    public static function add_finance_endpoint( $query_vars ) {
        $query_vars['zh-finance'] = 'zh-finance';
        return $query_vars;
    }

    public static function load_finance_template( $query_vars ) {
        if ( isset( $query_vars['zh-finance'] ) ) {
            dokan_get_template_part(
                'finance/dashboard',
                '',
                [ 'zerohold-finance', ZH_FINANCE_PATH . 'Templates/' ]
            );
            exit;
        }
    }
    
    public static function enqueue_assets() {
        if ( dokan_is_seller_dashboard() && get_query_var( 'zh-finance' ) ) {
            // Check if we need to enqueue any specific JS/CSS
        }
    }
}
