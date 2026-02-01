<?php

namespace ZeroHold\Finance\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FinanceObserver
 * 
 * Watches for WordPress, WooCommerce, and Dokan actions
 * and normalizes them into 'zh_finance_event' triggers.
 */
class FinanceObserver {

    public static function init() {
        // 1. Order Completed (The big one)
        add_action( 'dokan_order_status_completed', [ __CLASS__, 'on_dokan_order_completed' ], 10, 1 );
        
        // 2. Shipping Purchase (Coming soon)
        // add_action( 'zh_shipping_purchased', ... );
    }

    /**
     * Triggered when a Dokan sub-order is marked completed.
     */
    public static function on_dokan_order_completed( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Ensure it's a vendor order
        $vendor_id = dokan_get_seller_id_by_order( $order_id );
        if ( ! $vendor_id ) {
            return;
        }

        // Fire Canonical Event
        do_action( 'zh_finance_event', 'order_completed', [
            'order_id'  => $order_id,
            'vendor_id' => $vendor_id,
            'timestamp' => current_time( 'mysql' ),
        ]);
    }
}
